<?php

declare(strict_types=1);

namespace Componenta\App\Config;

use Componenta\ClassFinder\ClassFinder;
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
    ): ConfigFactoryResult {
        $environment ??= new EnvLoader($paths->baseDir)->load();
        $definition = self::definition($definition);
        $discovered = $definition->discovery === null ? null : new ClassFinder()->find(
            self::resolveDirectories($paths, $definition->discovery->directories),
            $definition->discovery->exclude,
        );
        $providers = self::providers($definition, $discovered);
        $providers[] = self::runtimeServices($paths, $discovered);
        $composition = (new RuntimeConfigFactory())->create($environment, ...$providers);

        return new ConfigFactoryResult(
            composition: $composition,
            discovered: $discovered,
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
    ): array {
        $providers = [];
        foreach ($definition->providers as $provider) {
            if ($provider instanceof ComposerPackageConfigProvider) {
                foreach ($provider->materialize($provider->classes()) as $materialized) {
                    $providers[] = self::prepareProvider($materialized, $discovered);
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

            $providers[] = self::prepareProvider($provider, $discovered);
        }
        return $providers;
    }

    private static function prepareProvider(
        callable $provider,
        ?ClassIteratorInterface $discovered,
    ): callable {
        return $provider instanceof DiscoveryAwareConfigProviderInterface
            ? $provider->withDiscovered($discovered)
            : $provider;
    }

    private static function runtimeServices(
        PathResolverInterface $paths,
        ?ClassIteratorInterface $discovered,
    ): callable {
        return static function () use ($paths, $discovered): array {
            $services = [PathResolverInterface::class => $paths];
            if ($discovered !== null) {
                $services[\Componenta\App\ConfigKey::DISCOVERY_SOURCE] = $discovered;
                $services[ClassIteratorInterface::class] = $discovered;
            }

            return [
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::SERVICES => $services,
                ],
            ];
        };
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
