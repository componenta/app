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

ConfigFactory registers `PathResolverInterface`. With discovery configured, one lazy source `ClassIteratorInterface` is passed to discovery-aware providers and registered in DI also under `ConfigKey::DISCOVERY_SOURCE`. Development and production use the same source. Builders share it through DI; their maps are loaded by the corresponding runtime service factories.

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

BuildCommand receives a closure that resolves the orchestrator from the existing container. It calls that closure only in `execute()`. Thus `list` and `--help` leave builders unconstructed; ordinary application bootstrap still runs. The command is available in development and production, including before artifacts exist. Runtime services can use artifacts or fall back to source data; rebuilding is explicit.

## Bootloaders and discovery

`ConfigKey::BOOTLOADERS` registers bootloader services. `BootloaderInterface::boot()` receives `BootContext` with the current scope, boot target and `ContainerValue`. The base `Bootloader` supports an injectable `__invoke()` method.

`ClassDiscoveryBootloader` passes the runtime class iterator to `ClassListenerNotifier`. Listener processing uses the source selected by ConfigFactory.

`#[Boot]` marks public startup methods. `BootMethodInvocation` collects them and, when finalized, passes them to `BootInvocationRunner` for execution by descending priority. Explicit boot parameters can contain plain values or DI metadata: `EntryId` for a service, `Config` for a setting, and `Env` for an environment value.

## Cache paths

`CacheLayout` exposes build, development and runtime directories. Bootstrap uses `ConfigKey::DEFAULT_CACHE_BUILD_DIR` for build artifacts; `CacheLayout::fromConfig()` reads `ConfigKey::CACHE_DEV_DIR` and `ConfigKey::CACHE_RUNTIME_DIR` for the other roots. Concrete builders receive their artifact paths through their factories.

## Runtime integrations

- `componenta/app-console` supplies Symfony Console and its command registry.
- `componenta/app-http` supplies the HTTP application.
- `componenta/websocket-app` supplies the WebSocket application scope.
- `componenta/cqrs-app` supplies CQRS discovery, runtime maps and its builder.
- `componenta/interceptor-app` supplies interceptor metadata discovery and its builder.

Reusable runtime libraries can be used independently; their app packages connect configuration, discovery, builders and startup.
