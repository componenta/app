<?php

declare(strict_types=1);

namespace Componenta\App\Config;

use Closure;
use Componenta\ClassFinder\ClassFinder;
use Componenta\ClassFinder\ClassIterator;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\ConfigFactory as RuntimeConfigFactory;
use Componenta\Config\ConfigKey;
use Componenta\Config\Environment;
use Componenta\Config\Loader\EnvLoader;
use Componenta\Stdlib\PathResolverInterface;
use ErrorException;
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
        $prepared = false;
        if ($definition->discovery !== null) {
            $createSource = static fn (): ClassIteratorInterface => (new ClassFinder())->find(
                self::resolveDirectories($paths, $definition->discovery->directories),
                $definition->discovery->exclude,
            );
            $discovered = self::preparedDiscovery($paths);
            $prepared = $discovered !== null;
            if ($discovered === null) {
                $source = $discovered = $createSource();
            } else {
                $source = $createSource;
            }
        }
        $providers = self::providers($definition, $discovered);
        $providers[] = self::runtimeServices($paths, $discovered, $source, $prepared);
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
        bool $prepared,
    ): callable {
        return static function () use ($paths, $discovered, $source, $prepared): array {
            $services = [
                PathResolverInterface::class => $paths,
                \Componenta\App\ConfigKey::DISCOVERY_PREPARED => $prepared,
            ];
            $factories = [];
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

    private static function preparedDiscovery(PathResolverInterface $paths): ?ClassIteratorInterface
    {
        $file = $paths->resolve(\Componenta\App\ConfigKey::DISCOVERY_MAP_FILE);
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $map = (static fn (string $file): mixed => require $file)($file);
            return is_array($map) ? ClassIterator::fromMap($map) : null;
        } catch (\Throwable) {
            // An incomplete or incompatible artifact leaves ordinary discovery available.
            return null;
        } finally {
            restore_error_handler();
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
