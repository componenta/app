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

`run()` changes the working directory to the project root, loads `config/container.php`, checks its PSR-11 container result, and delegates to `Runner`. The runner creates the scoped application through `AppFactory`, adapts its boot target, runs bootloaders, and starts the application.

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

A usable map selects prepared discovery until it is removed or rebuilt, independently of `APP_ENV`. Changes to discovery inputs require another build. Delete the map to return to source discovery. Missing or unusable maps fall back to the source without writing files at runtime.

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

`ConfigKey::BOOTLOADERS` registers bootloader services. `BootloaderInterface::boot()` receives `BootContext` with the current scope, boot target and `ContainerValue`. The base `Bootloader` supports an injectable `__invoke()` method.

`ClassDiscoveryBootloader` passes the runtime class iterator to `ClassListenerNotifier`. Listener processing uses the source selected by ConfigFactory.

`#[Boot]` marks public startup methods. `BootMethodInvocation` collects them and, when finalized, passes them to `BootInvocationRunner` for execution by descending priority. Explicit boot parameters can contain plain values or DI metadata: `EntryId` for a service, `Config` for a setting, and `Env` for an environment value.

With discovery configured, `BootBuilder` writes `var/cache/build/boot.php`: each class maps to the ordered public methods carrying `#[Boot]`, including inherited methods. Attributes and nested parameter objects are instantiated natively at runtime. The map avoids enumerating all methods; missing or malformed maps use the original path. Source discovery ignores previous boot maps. Use `app:clean` to remove build artifacts before the next application startup needs fresh discovery.

## Cache paths

Concrete builders receive their artifact paths through their factories using `PathResolverInterface`. The discovery map path is `ConfigKey::DISCOVERY_MAP_FILE`. Each builder removes its own artifacts when implementing the optional cleanup contract.

## Runtime integrations

- `componenta/app-console` supplies Symfony Console and its command registry.
- `componenta/app-http` supplies the HTTP application.
- `componenta/websocket-app` supplies the WebSocket application scope.
- `componenta/cqrs-app` supplies CQRS discovery, runtime maps and its builder.
- `componenta/interceptor-app` supplies interceptor metadata discovery and its builder.

Reusable runtime libraries can be used independently; their app packages connect configuration, discovery, builders and startup.
