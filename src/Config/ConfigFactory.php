<?php

declare(strict_types=1);

namespace Componenta\App\Config;

use Closure;
use Componenta\App\Build\BuildStore;
use Componenta\App\Build\PreparedBuild;
use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\ClassFinder\ClassFinder;
use Componenta\ClassFinder\ClassIterator;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\ConfigFactory as RuntimeConfigFactory;
use Componenta\Config\ConfigKey;
use Componenta\Config\Environment;
use Componenta\Config\Loader\EnvLoader;
use Componenta\Stdlib\PathResolverInterface;
use RuntimeException;

final class ConfigFactory
{
    private function __construct()
    {
    }

    /**
     * @param ConfigDefinitionInterface|callable(): ConfigDefinitionInterface $definition
     */
    public static function create(
        PathResolverInterface $paths,
        ConfigDefinitionInterface|callable $definition,
        ?Environment $environment = null,
        bool $source = false,
        array $requiredArtifacts = [],
    ): ConfigFactoryResult {
        $environment ??= new EnvLoader($paths->baseDir)->load();
        $store = self::buildStore($paths, $environment, $requiredArtifacts);
        $sourceOverride = $environment->bool('APP_DISCOVERY_SOURCE', false);
        if (!$source && $sourceOverride && $requiredArtifacts !== []) {
            throw new RuntimeException('APP_DISCOVERY_SOURCE conflicts with required artifacts; use explicit source bootstrap for preparation.');
        }
        // Decide before loading the definition or invoking any configuration provider.
        $prepared = $source || $sourceOverride ? null : $store->load();
        $definition = self::definition($definition);
        $sourceIterator = null;
        $discovered = null;
        $preparedDiscovery = false;
        $configuredDiscovery = $definition->discovery;
        if ($configuredDiscovery !== null) {
            // Capture and validate custom definitions before creating the lazy source.
            $discovery = new DiscoveryDefinition($configuredDiscovery->directories, $configuredDiscovery->exclude);
            $createSource = static fn (): ClassIteratorInterface => (new ClassFinder())->find(
                self::resolveDirectories($paths, $discovery->directories),
                $discovery->exclude,
            );
            $discovered = $prepared === null ? null : self::preparedDiscovery($prepared);
            $preparedDiscovery = $discovered !== null;
            if ($discovered === null) {
                if ($prepared !== null && $requiredArtifacts !== []) {
                    throw new RuntimeException('Published discovery is unusable; required artifacts prohibit falling back to a source bootstrap.');
                }
                $prepared = null;
                $sourceIterator = $discovered = $createSource();
            } else {
                $sourceIterator = $createSource;
            }
        }
        $providerClasses = $prepared?->read(AppConfigKey::CONFIG_PROVIDERS_ARTIFACT);
        if (!self::validProviderClasses($providerClasses)) {
            if ($prepared !== null && in_array(AppConfigKey::CONFIG_PROVIDERS_ARTIFACT, $requiredArtifacts, true)) {
                throw new RuntimeException('Required configuration provider artifact has an invalid class list.');
            }
            $providerClasses = null;
        }
        $providers = self::providers($definition, $discovered, $providerClasses);
        $providers[] = self::runtimeServices($paths, $discovered, $sourceIterator, $store, $prepared, $preparedDiscovery);
        $composition = (new RuntimeConfigFactory())->create($environment, ...$providers);

        return new ConfigFactoryResult(
            composition: $composition,
            discovered: $discovered,
        );
    }

    /** Bootstrap-level store configuration, deliberately independent of provider output. */
    public static function buildStore(
        PathResolverInterface $paths,
        ?Environment $environment = null,
        array $requiredArtifacts = [],
    ): BuildStore {
        $environment ??= new EnvLoader($paths->baseDir)->load();

        return new BuildStore(
            $paths->resolve(AppConfigKey::DEFAULT_CACHE_BUILD_DIR),
            $environment->has('APP_BUILD_ID') ? $environment->string('APP_BUILD_ID') : null,
            $requiredArtifacts,
        );
    }

    /**
     * @param ConfigDefinitionInterface|callable(): ConfigDefinitionInterface $definition
     */
    private static function definition(ConfigDefinitionInterface|callable $definition): ConfigDefinitionInterface
    {
        if ($definition instanceof ConfigDefinitionInterface) {
            return $definition;
        }

        $resolved = $definition();
        if (!$resolved instanceof ConfigDefinitionInterface) {
            throw new RuntimeException(sprintf(
                'Config definition loader must return %s, got %s.',
                ConfigDefinitionInterface::class,
                get_debug_type($resolved),
            ));
        }

        return $resolved;
    }

    /** @return list<callable(): mixed> */
    private static function providers(
        ConfigDefinitionInterface $definition,
        ?ClassIteratorInterface $discovered,
        ?array $providerClasses,
    ): array {
        $providers = [];
        foreach ($definition->providers as $provider) {
            if ($provider instanceof ComposerPackageConfigProvider) {
                foreach ($provider->materialize($provider->classes()) as $materialized) {
                    $providers[] = self::prepareProvider($materialized, $discovered, $providerClasses);
                }
                continue;
            }

            if (!is_callable($provider)) {
                throw new RuntimeException(sprintf(
                    'Config provider must be callable or %s, got %s.',
                    ComposerPackageConfigProvider::class,
                    get_debug_type($provider),
                ));
            }

            $providers[] = self::prepareProvider($provider, $discovered, $providerClasses);
        }
        return $providers;
    }

    private static function prepareProvider(
        callable $provider,
        ?ClassIteratorInterface $discovered,
        ?array $providerClasses,
    ): callable {
        $provider = $provider instanceof DiscoveryAwareConfigProviderInterface
            ? $provider->withDiscovered($discovered)
            : $provider;

        return $provider instanceof AttributeConfigProvider && $providerClasses !== null
            ? $provider->withPreparedProviders($providerClasses)
            : $provider;
    }

    private static function runtimeServices(
        PathResolverInterface $paths,
        ?ClassIteratorInterface $discovered,
        ClassIteratorInterface|Closure|null $source,
        BuildStore $store,
        ?PreparedBuild $prepared,
        bool $preparedDiscovery,
    ): callable {
        return static function () use ($paths, $discovered, $source, $store, $prepared, $preparedDiscovery): array {
            $services = [
                PathResolverInterface::class => $paths,
                BuildStore::class => $store,
                AppConfigKey::DISCOVERY_PREPARED => $preparedDiscovery,
            ];
            $factories = [];
            if ($prepared !== null) {
                $services[AppConfigKey::PREPARED_BUILD] = $prepared;
                $services[PreparedBuild::class] = $prepared;
            }
            if ($discovered !== null) {
                $services[ClassIteratorInterface::class] = $discovered;
                if ($source instanceof Closure) {
                    $factories[\Componenta\App\ConfigKey::DISCOVERY_SOURCE] = $source;
                } else {
                    $services[\Componenta\App\ConfigKey::DISCOVERY_SOURCE] = $source;
                }
            }

            return [
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::SERVICES => $services,
                    ...($factories === [] ? [] : [ConfigKey::FACTORIES => $factories]),
                ],
            ];
        };
    }

    private static function preparedDiscovery(PreparedBuild $build): ?ClassIteratorInterface
    {
        $map = $build->read(AppConfigKey::DISCOVERY_ARTIFACT);
        if ($map === null) {
            return null;
        }

        try {
            return ClassIterator::fromMap($map);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function validProviderClasses(?array $classes): bool
    {
        if ($classes === null || !array_is_list($classes)) {
            return false;
        }
        $seen = [];
        foreach ($classes as $class) {
            if (!is_string($class) || $class === '') {
                return false;
            }
            $key = strtolower(ltrim($class, '\\'));
            if ($key === '' || isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
        }
        return true;
    }

    /**
     * @param list<string> $directories
     * @return list<string>
     */
    private static function resolveDirectories(PathResolverInterface $paths, array $directories): array
    {
        return array_map($paths->resolve(...), $directories);
    }
}
