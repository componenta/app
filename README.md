# Componenta App

Application composition and startup for Componenta projects: configuration providers, scopes, adapters, bootloaders and application builders.

## Installation

~~~bash
composer require componenta/app
~~~

PHP 8.5.13 or later is required. Composer metadata exposes `Componenta\App\ConfigProvider`; `componenta/composer-plugin` adds it to the generated provider list.

The provider registers `ApplicationBuildOrchestratorFactory`, the application and boot-target factories, `DateTimeBootloader`, `ClassDiscoveryBootloader`, and the `BootMethodInvocation` class listener. HTTP, CLI and WebSocket runtimes are supplied by their integration packages.

## Application entry point

Each runtime has an entry file, such as `public/index.php` or `bin/console.php`:

~~~php
use Componenta\App\Scope;
use Componenta\Stdlib\PathResolver;

use function Componenta\App\run;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

run(Scope::CLI, new PathResolver($root));
~~~

`run()` changes the working directory to the project root, loads `config/container.php`, checks its PSR-11 container result, and delegates to `Runner`. The runner resolves the scoped application through `AppFactory`, creates a separate boot target, runs bootloaders, and starts the application only after they finish.

Runtime integration providers register a scope value and a container service ID in `ConfigKey::APPLICATIONS`:

~~~php
use Componenta\App\ConfigKey;
use Componenta\App\Scope;

return [
    ConfigKey::APPLICATIONS => [
        Scope::HTTP->value => MyHttpApplication::class,
    ],
];
~~~

The registered service must implement `AppInterface`. Scope values are exact keys; a custom scope needs its own registration. The application factory resolves only the selected service. The boot target adapter remains a separate extension point and must wrap the application in a capability-only object, never return it or expose its `run()` method. Bootloaders obtain this object through `BootContext::target(Contract::class)`. The container remains trusted application infrastructure, not an isolation or security sandbox.

## Configuration and container

`config/config.php` returns a `ConfigDefinition`. Providers are processed in registration order:

~~~php
use Componenta\App\Config\ComposerPackageConfigProvider;
use Componenta\App\Config\ConfigDefinition;
use Componenta\App\Config\DiscoveryDefinition;

return new ConfigDefinition(
    providers: [
        new ComposerPackageConfigProvider(
            $paths->resolve('config/componenta-providers.php'),
        ),
    ],
    discovery: new DiscoveryDefinition(directories: ['src']),
);
~~~

Add application providers and file providers as required. Add `AttributeConfigProvider` for `#[AsConfig]` contributions. Omitting `discovery` disables class discovery.

The composition root `config/container.php` passes the two parts of the configuration to DI:

~~~php
use Componenta\App\Config\ConfigFactory;
use Componenta\DI\ContainerFactory;

$definition = require $paths->resolve('config/config.php');
$result = ConfigFactory::create(paths: $paths, definition: $definition);

return (new ContainerFactory())->create(
    $result->composition->config,
    $result->composition->dependencies,
)->container;
~~~

Runtime `Config` holds application settings; `DependencyDefinitions` holds DI registrations. The container receives the same Config instance. ConfigFactory invokes providers in both development and production.

ConfigFactory registers `PathResolverInterface` and `BuildStore`. With discovery configured, it selects a published `app.discovery` artifact or a shared source iterator. Prepared discovery avoids directory traversal and tokenization. Without a usable publication, ordinary startup uses source discovery without writing a runtime cache. `APP_ENV` does not select a different composition algorithm.

The selected `PreparedBuild` is pinned once before configuration providers run. Optional consumers must check `ConfigKey::PREPARED_BUILD`, then resolve that service as `PreparedBuild`. Checking `has(PreparedBuild::class)` is insufficient with an autowiring container. All consumers of the selected snapshot read the same generation even if another build publishes concurrently.

`AttributeConfigProvider` still creates and invokes each provider on every configuration composition. The `app.config-providers` artifact is an ordered list of provider class names, not cached provider results. It avoids reflecting unrelated declarations while preserving provider order, lazy contributions and current runtime values. Missing metadata falls back to the selected discovery iterator. Build never rewrites `config/config.php` or snapshots the complete Config/DI container.

Custom hosts can supply a prepared class list through the `AttributeConfigProvider` constructor or `withPreparedProviders()`. The list is captured independently of caller-owned PHP references; provider instances and returned configuration values are still created on every composition.

Set `source: true` in `ConfigFactory::create()` or `APP_DISCOVERY_SOURCE=1` before bootstrap to ignore prepared metadata. Source selection happens before definition loading and provider invocation. Builders receive shared source discovery through `ConfigKey::DISCOVERY_SOURCE`; use a fresh source bootstrap for each build, rather than treating a reused container as a filesystem watcher.

## Application build protocol

A participant owns its artifact IDs and payload schemas. It implements one interface:

~~~php
use Componenta\App\Build\ApplicationBuilderInterface;
use Componenta\App\Build\BuildContext;

final readonly class MetadataBuilder implements ApplicationBuilderInterface
{
    public function __construct(private MetadataSource $source) {}

    public function build(BuildContext $build): void
    {
        $build->write('my-app.metadata', $this->source->metadata(), schemaVersion: 1);
    }
}
~~~

Register builder service IDs in `ConfigKey::BUILDERS`. DI factories inject source dependencies; constructors must not write artifacts. The orchestrator validates the entire ordered list and resolves every participant before invoking any `build()` method. Empty lists are valid and publish an empty generation.

`BuildContext::write()` serializes synchronously into a private staging generation. Maps support arrays, scalars and enum cases. Unsupported runtime values fail immediately; a participant may catch a known portability error and call `skip($id, $reason)` to explicitly retain its source fallback. Other failures should propagate. A duplicate ID, invalid ID or write after publication is rejected. The context is a live write capability: it cannot be cloned or serialized, and closes when the run publishes or fails. Participants cannot consume another participant's output during this run and must not write active files or perform external migrations through this protocol.

Each successful run publishes a manifest and then atomically replaces `var/cache/build/current.json`. Until that last operation, new bootstraps continue seeing the previous complete generation. An exception stops subsequent participants and leaves the active publication unchanged. The protocol does not roll back arbitrary external effects. Files live under `var/cache/build/generations/<generation>/`; individual participants never choose these paths.

The core artifacts are:

| ID | Payload |
| --- | --- |
| `app.discovery` | Ordered declaration records with file, name, kind and modifiers |
| `app.config-providers` | Ordered `#[AsConfig]` provider class names |
| `app.boot` | Public method names carrying `#[Boot]`, grouped by class |

Runtime integrations own additional IDs. Their readers use `PreparedBuild::read($id, $schemaVersion)`: an absent, skipped, unreadable or incompatible optional artifact returns `null` and permits the package's source fallback. The selected snapshot exposes skip reasons through `skipped()`.

### Fresh build bootstrap

`ApplicationBuildRunner` composes source configuration and a fresh DI container, then invokes the orchestrator. It changes to the application directory for composition and restores the caller's directory afterward. It never runs application bootloaders or `#[Boot]` methods. Consequently, a newly added `#[AsConfig]` provider can register a builder in the same build, even while an older generation is active.

~~~php
use Componenta\App\Build\ApplicationBuildRunner;

$prepared = ApplicationBuildRunner::build(
    paths: $paths,
    definition: static fn () => require $paths->resolve('config/config.php'),
);
~~~

`componenta/app-console` exposes this operation as `app:build`. Its early build dispatcher must run before the ordinary application runner, so stale runtime configuration or application boot effects cannot prevent the build command from starting. Invoking the orchestrator from an already prepared runtime container does not offer that freshness guarantee.

### Identity and required artifacts

Set `APP_BUILD_ID` to the same immutable release identity during build and runtime to bind the publication to that release. A different identity, including a missing identity where the build supplied one, rejects the publication. Without an explicit identity, the contract is that application sources, installed dependencies and build-affecting configuration remain immutable until the next build. No source tree hashes or modification-time scans are computed at startup. Do not copy artifacts between incompatible filesystem roots; declaration records preserve their source paths.

Required artifacts are an explicit deployment policy, never inferred from `APP_ENV`:

~~~php
$result = ConfigFactory::create(
    paths: $paths,
    definition: $definition,
    requiredArtifacts: ['app.discovery', 'app.config-providers', 'router.routes'],
);
~~~

Pass the same list to `ApplicationBuildRunner::build(..., requiredArtifacts: [...])` to reject a missing or skipped artifact before publication. Explicit source build bootstrap ignores old publications while retaining this publication requirement. A runtime `APP_DISCOVERY_SOURCE=1` override combined with a nonempty required-artifact policy is rejected as contradictory; use the explicit source API for preparation or deliberately remove the runtime requirement for recovery. Runtime required-artifact checks reject missing publications, identity mismatches, unreadable payloads and incompatible schema versions. A strict deployment also rejects unusable core discovery instead of discarding the selected generation and bypassing required readers. The mutable current pointer is JSON data; immutable PHP manifests and payloads remain safe to cache with OPcache timestamp checks disabled. Each package remains responsible for validating its payload's domain structure. These checks do not prove application semantics; exercise the prepared runtime before activating a release.

### Cleanup and deployment

`ApplicationBuildRunner::clean($paths)` and `app:clean` remove only the current pointer. Cleanup accepts the path resolver alone: it does not load dotenv, validate the release identity, instantiate builders or load artifact PHP. A broken environment file therefore does not prevent unpublishing. Existing workers retain their pinned generations; new bootstraps select source, or fail if their required-artifact policy demands a publication. Successful rebuilds need no preliminary clean.

Old generations and failed staging generations are intentionally retained. Remove them offline only after no worker can still reference them, or retire the complete release directory. No online garbage collector is provided. `ApplicationBuildCleanerInterface` and participant-owned cleanup were removed.

PHP artifacts are trusted executable deployment files. Keep the build directory outside the public web root and writable only by deployment identities. Readers reject symlink files and group/other-writable files on POSIX. Publish before starting or reloading workers. Legacy flat `classes.php`/`boot.php` maps are ignored; rebuild with the new protocol. Publication format 2 separates metadata filenames from valid artifact IDs, including `manifest`. Rebuild publications from earlier store formats before deployment.

## Bootloaders and discovery

The ordered `ConfigKey::BOOTLOADERS` list registers unique bootloader class-strings. The provider validates registrations and resolves services before execution, then checks `supports()` once for each candidate in order. `BootloaderInterface::boot()` receives `BootContext` with the current scope, typed target access and `ContainerValue`. Direct callers must check applicability before invoking `boot()`. The base `Bootloader` supports an injectable `__invoke()` method. `DateTimeBootloader` applies `APP_TIMEZONE` (UTC by default) to HTTP, CLI and WebSocket and rejects an invalid timezone.

`ClassDiscoveryBootloader` passes the runtime class iterator to `ClassListenerNotifier`. Listener processing uses the source selected by ConfigFactory.

`#[Boot]` marks public startup methods. `BootMethodInvocation` collects them once per discovered declaration and, when finalized, passes them to `BootInvocationRunner` for execution by descending priority. Repeated attributes on the same method retain their declared semantics. The listener releases its collected metadata when finalization starts and cannot be reused for another discovery pass. Explicit boot parameters can contain plain values or DI metadata: `EntryId` for a service, `Config` for a setting, and `Env` for an environment value.

With discovery configured, `BootBuilder` contributes `app.boot`: each class maps to the ordered public methods carrying `#[Boot]`, including inherited methods. Attributes and nested parameter objects are instantiated natively at runtime. The map avoids enumerating all methods and skips autoloading declarations with no boot methods; missing or malformed optional metadata uses the original path. Source bootstrap ignores the published boot metadata. Actual boot attributes and their parameter objects remain runtime-owned.

## Cache paths

BuildStore owns artifact placement under `ConfigKey::DEFAULT_CACHE_BUILD_DIR`. Development and runtime caches are separate from build generations.

## Runtime integrations

- `componenta/app-console` supplies Symfony Console and its command registry.
- `componenta/app-http` supplies the HTTP application.
- `componenta/websocket-app` supplies the WebSocket application scope.
- `componenta/cqrs-app` supplies CQRS discovery, runtime maps and its builder.
- `componenta/interceptor-app` is an optional migration provider. Interceptor 4 owns runtime metadata and needs no application builder.

Reusable runtime libraries can be used independently; their app packages connect configuration, discovery, builders and startup.

## Performance benchmarks

Install development dependencies and run PHPBench:

```sh
composer bench
vendor/bin/phpbench run --filter=StartupBench --report=aggregate
vendor/bin/phpbench run --filter=RuntimeBench --report=aggregate
```

The suite measures cold configuration, container creation, discovery, boot discovery and complete startup with 300 and 1,000 declarations in source and prepared modes; attribute and Composer package configuration composition; build and cleanup; and warm runtime service resolution, boot target creation, bootloader selection, DI boot parameter resolution and the runner without discovery. Fixture preparation and behavioral checks run outside the measured subjects. Attribute configuration has separate source and prepared subjects: the prepared provider index must autoload only matching providers, while source composition scans declarations. Prepared artifacts are produced in a separate PHP process using the real builders, so they do not warm the measured process. Cold subjects use one revolution per isolated iteration; warm subjects use repeated revolutions. CLI OPcache and Xdebug are disabled by default. The aggregate report contains time, memory and variation; CI runs a shorter sample without timing thresholds.

Use `vendor/bin/phpbench run --tag=baseline --report=aggregate` to store a baseline and `vendor/bin/phpbench run --ref=baseline --report=aggregate` to compare a subsequent run on the same machine. These synthetic measurements exclude process startup itself and application-specific HTTP I/O; they are not TTFB measurements.
