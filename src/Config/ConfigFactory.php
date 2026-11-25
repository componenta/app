<?php

declare(strict_types=1);

namespace Componenta\App\Config;

use Closure;
use Componenta\App\Build\PhpMapFile;
use Componenta\App\Internal\BuildArtifact;
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
    ): ConfigFactoryResult {
        $environment ??= new EnvLoader($paths->baseDir)->load();
        $definition = self::definition($definition);
        $source = null;
        $discovered = null;
        $generation = null;
        if ($definition->discovery !== null) {
            $createSource = static fn (): ClassIteratorInterface => (new ClassFinder())->find(
                self::resolveDirectories($paths, $definition->discovery->directories),
                $definition->discovery->exclude,
            );
            $prepared = $environment->bool('APP_DISCOVERY_SOURCE', false)
                ? null
                : self::preparedDiscovery($paths);
            $discovered = $prepared['iterator'] ?? null;
            $generation = $prepared['generation'] ?? null;
            if ($discovered === null) {
                $source = $discovered = $createSource();
            } else {
                $source = $createSource;
            }
        }
        $providers = self::providers($definition, $discovered);
        $providers[] = self::runtimeServices($paths, $discovered, $source, $generation);
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
        ClassIteratorInterface|Closure|null $source,
        ?string $generation,
    ): callable {
        return static function () use ($paths, $discovered, $source, $generation): array {
            $services = [
                PathResolverInterface::class => $paths,
                \Componenta\App\ConfigKey::DISCOVERY_PREPARED => $generation !== null,
            ];
            $factories = [];
            if ($generation !== null) {
                $services[\Componenta\App\ConfigKey::DISCOVERY_GENERATION] = $generation;
            }
            if ($discovered !== null) {
                $factories[\Componenta\App\ConfigKey::BUILD_GENERATION] = BuildArtifact::newGeneration(...);
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

    /** @return array{iterator: ClassIteratorInterface, generation: string}|null */
    private static function preparedDiscovery(PathResolverInterface $paths): ?array
    {
        $file = $paths->resolve(\Componenta\App\ConfigKey::DISCOVERY_MAP_FILE);
        $artifact = BuildArtifact::unwrap(PhpMapFile::read($file));
        if ($artifact === null) {
            return null;
        }

        try {
            return [
                'iterator' => ClassIterator::fromMap($artifact['payload']),
                'generation' => $artifact['generation'],
            ];
        } catch (\Throwable) {
            return null;
        }
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
