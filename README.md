# Componenta App

Application composition and startup for Componenta projects: configuration providers, scopes, adapters, bootloaders and application builders.

## Installation

~~~bash
composer require componenta/app
~~~

PHP 8.4 or later is required. Composer metadata exposes `Componenta\App\ConfigProvider`; `componenta/composer-plugin` adds it to the generated provider list.

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

ConfigFactory registers `PathResolverInterface`. With discovery configured, it passes a shared `ClassIteratorInterface` to discovery-aware providers and runtime listeners. Without a build map, discovery reads the source files on each application startup; there is no development disk cache or freshness scan.

`app:build` also runs `DiscoveryBuilder` and atomically writes `var/cache/build/classes.php`. Its ordered records preserve each declaration's source filename, fully qualified name, kind, and abstract/final/readonly flags, including multiple declarations from one file. `ClassIterator::fromMap()` reconstructs a normal `ClassIterator` from this map without traversing source directories or tokenizing files.

A usable map selects prepared discovery until it is removed or rebuilt, independently of `APP_ENV`. Changes to discovery inputs require another build. Missing or unusable maps fall back to the source without writing files at runtime. Set `APP_DISCOVERY_SOURCE=1` to force source discovery for a recovery startup, even if stale artifacts remain.

The App discovery and boot maps carry a format version and a shared build generation. A boot map from a different generation, including an incomplete or interrupted rebuild, is ignored. Legacy unversioned App maps are ignored and can be rebuilt. Integration builders using `PhpMapFile` can write generation-bound maps with `PhpMapFile::write($file, $payload, $generation)` and read them with `PhpMapFile::read($file, $expectedGeneration)`. Obtain the build generation from `ConfigKey::BUILD_GENERATION` and the selected prepared generation from `ConfigKey::DISCOVERY_GENERATION` when that service exists. Each package still owns its payload format.

PHP maps are executable, trusted deployment artifacts. Store them outside the public web root in directories writable only by trusted deployment identities; never accept an uploaded or user-controlled map. The reader rejects symbolic-link map files and group/other-writable files on POSIX. Unusable maps that produce accidental output are rejected without emitting that output. Publish completed artifacts before starting or reloading workers.

`AttributeConfigProvider` stays in the application configuration and consumes the selected iterator. It continues finding and invoking configuration providers through the same attribute logic. `FileProvider` registrations and configuration merge order are unchanged. Build never edits `config/config.php`.

Builders receive the current source iterator through `ConfigKey::DISCOVERY_SOURCE`, independently of the runtime map. With a prepared runtime map, this fresh source remains a lazy shared DI factory until requested. Rebuilds therefore do not copy the previous discovery snapshot.

## Application builders

`componenta/app-console` provides the ordinary console command:

~~~bash
php bin/console.php app:build
~~~

A builder implements `Componenta\App\Build\ApplicationBuilderInterface`:

~~~php
namespace App\Build;

use Componenta\App\Build\ApplicationBuilderInterface;

final class SearchIndexBuilder implements ApplicationBuilderInterface
{
    public function __construct(private SearchIndexWriter $writer)
    {
    }

    public function build(): void
    {
        $this->writer->rebuild();
    }
}
~~~

`SearchIndexWriter` is an application service responsible for the index format and publication. Construction prepares dependencies; `build()` performs the work.

A package or application ConfigProvider registers service IDs:

~~~php
namespace App;

use App\Build\SearchIndexBuilder;
use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\Config\ConfigProvider as BaseConfigProvider;

final class ConfigProvider extends BaseConfigProvider
{
    protected function getConfig(): array
    {
        return [
            AppConfigKey::BUILDERS => [SearchIndexBuilder::class],
        ];
    }
}
~~~

DI autowiring can create the builder. Register a factory with `getFactories()` when dependencies require explicit configuration, such as a metadata source or a path resolved through `PathResolverInterface`.

`ApplicationBuildOrchestratorFactory` validates the entire ordered list: it must contain unique, non-empty string service IDs. It resolves every service and checks `ApplicationBuilderInterface` before any builder runs. An absent or empty list completes successfully.

`ApplicationBuildOrchestrator::build()` calls builders in order. Each builder owns its artifact format, directories and atomic writes. An exception propagates unchanged and stops subsequent builders; completed effects remain. Builders work independently and receive shared source data through dependencies.

BuildCommand and CleanCommand each receive a closure that resolves the orchestrator from the existing container. Each command calls its closure only in `execute()`. Thus `list` and `--help` leave builders unconstructed; ordinary application bootstrap still runs. Both commands are available in development and production, including before artifacts exist. Runtime services can use artifacts or fall back to source data; rebuilding is explicit.

Builders may additionally implement `Componenta\App\Build\ApplicationBuildCleanerInterface`:

~~~php
interface ApplicationBuildCleanerInterface
{
    public function clean(): void;
}
~~~

`ApplicationBuildOrchestrator::clean()` visits the same registered builders in order and calls only those implementing this interface. No separate registration is needed. The builder removes only artifacts it owns; already absent artifacts are a successful no-op. A failure propagates unchanged and stops later cleanup, leaving completed effects in place. Empty lists succeed. The orchestrator does not delete files itself.

`app:build` builds and `app:clean` cleans. Both use the ordinary configuration and container, with existing maps when available. Cleaning does not recreate the current container; a subsequent process starts without the removed maps. The entrypoint does not inspect command names. To include newly discovered configuration providers in a rebuild, run `app:clean` and then `app:build` as separate processes.

## Bootloaders and discovery

The ordered `ConfigKey::BOOTLOADERS` list registers unique bootloader class-strings. The provider validates registrations and resolves services before execution, then checks `supports()` once for each candidate in order. `BootloaderInterface::boot()` receives `BootContext` with the current scope, typed target access and `ContainerValue`. Direct callers must check applicability before invoking `boot()`. The base `Bootloader` supports an injectable `__invoke()` method. `DateTimeBootloader` applies `APP_TIMEZONE` (UTC by default) to HTTP, CLI and WebSocket and rejects an invalid timezone.

`ClassDiscoveryBootloader` passes the runtime class iterator to `ClassListenerNotifier`. Listener processing uses the source selected by ConfigFactory.

`#[Boot]` marks public startup methods. `BootMethodInvocation` collects them once per discovered declaration and, when finalized, passes them to `BootInvocationRunner` for execution by descending priority. Repeated attributes on the same method retain their declared semantics. The listener releases its collected metadata when finalization starts and cannot be reused for another discovery pass. Explicit boot parameters can contain plain values or DI metadata: `EntryId` for a service, `Config` for a setting, and `Env` for an environment value.

With discovery configured, `BootBuilder` writes `var/cache/build/boot.php`: each class maps to the ordered public methods carrying `#[Boot]`, including inherited methods. Attributes and nested parameter objects are instantiated natively at runtime. The map avoids enumerating all methods and skips autoloading declarations with no boot methods; missing, malformed or mismatched-generation maps use the original path. Source discovery ignores previous boot maps. Use `app:clean` to remove build artifacts before the next application startup needs fresh discovery.

## Cache paths

Concrete builders receive their artifact paths through their factories using `PathResolverInterface`. The discovery map path is `ConfigKey::DISCOVERY_MAP_FILE`. Each builder removes its own artifacts when implementing the optional cleanup contract.

## Runtime integrations

- `componenta/app-console` supplies Symfony Console and its command registry.
- `componenta/app-http` supplies the HTTP application.
- `componenta/websocket-app` supplies the WebSocket application scope.
- `componenta/cqrs-app` supplies CQRS discovery, runtime maps and its builder.
- `componenta/interceptor-app` supplies interceptor metadata discovery and its builder.

Reusable runtime libraries can be used independently; their app packages connect configuration, discovery, builders and startup.

## Performance benchmark

Run `php benchmarks/bootstrap.php 500 7` after installing development dependencies. The benchmark measures application configuration, container creation and boot method discovery/execution for the same synthetic declarations in fresh PHP processes, in source and prepared modes. It reports median wall time, autoload counts and peak memory. It is not an HTTP request/TTFB benchmark and uses no timing threshold in CI.
