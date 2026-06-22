<?php

declare(strict_types=1);

namespace Componenta\App\Config;

use Componenta\App\Cache\CacheLayout;
use Componenta\App\Discovery\Compile\CompileCache;
use Componenta\App\Discovery\Discovery;
use Componenta\Stdlib\PathResolverInterface;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\Config;
use Componenta\Config\ConfigLoader;
use Componenta\Config\Environment;
use Componenta\Config\Loader\EnvLoader;
use RuntimeException;

final class ConfigFactory
{
    private function __construct() {}

    /**
     * @param ConfigDefinitionInterface|callable(): ConfigDefinitionInterface $definition
     */
    public static function create(
        PathResolverInterface $paths,
        ConfigDefinitionInterface|callable $definition,
    ): ConfigFactoryResult {
        $bootstrapCache = CacheLayout::bootstrap($paths);
        $envFromFile = new EnvLoader($paths->baseDir)->load(override: true);
        $env = $envFromFile ?? Environment::fromGlobals();

        if ($env->get('APP_ENV', 'development') !== 'development') {
            $cached = ConfigLoader::loadFromFile($bootstrapCache->config);

            return new ConfigFactoryResult(
                config: new Config($cached->toArray(), $env),
            );
        }

        $definition = self::definition($definition);
        $providers = self::providers($definition->providers);
        $cache = CacheLayout::fromConfig(ConfigLoader::load($env, ...$providers), $paths);
        $discovery = $definition->discovery;
        $discovered = null;
        $cachedDelta = null;
        $compileCache = null;
        $discoveryCacheFile = null;

        if ($discovery !== null) {
            $discoveryCacheFile = $cache->devDiscovery;
            $discovered = new Discovery($discoveryCacheFile)->discover(
                dirs:    self::resolveDirectories($paths, $discovery->directories),
                exclude: $discovery->exclude,
            );
            $compileCache = new CompileCache(
                cacheFile:    $cache->devCompile,
                baselineFile: $discoveryCacheFile,
            );
            $cachedDelta = $compileCache->load();
        }

        $providers = self::prepareProviders(
            providers:          $providers,
            discovered:         $discovered,
            cache:              $cache,
            discoveryCacheFile: $discoveryCacheFile,
        );

        if ($cachedDelta !== null) {
            $providers = self::withCachedDelta($providers, $cachedDelta);
        }

        return new ConfigFactoryResult(
            config: ConfigLoader::load($env, ...$providers),
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

    /**
     * @param iterable<callable(): array> $providers
     *
     * @return list<callable(): array>
     */
    private static function providers(iterable $providers): array
    {
        $result = [];

        foreach ($providers as $provider) {
            if (!is_callable($provider)) {
                throw new RuntimeException(sprintf(
                    'Config provider must be callable, got %s.',
                    get_debug_type($provider),
                ));
            }

            $result[] = $provider;
        }

        return $result;
    }

    /**
     * @param list<callable(): array> $providers
     *
     * @return list<callable(): array>
     */
    private static function prepareProviders(
        array $providers,
        ?ClassIteratorInterface $discovered,
        CacheLayout $cache,
        ?string $discoveryCacheFile,
    ): array {
        $prepared = [];

        foreach ($providers as $provider) {
            if ($provider instanceof DiscoveryAwareConfigProviderInterface) {
                $provider = $provider->withDiscovered($discovered);
            }

            if ($provider instanceof AttributeConfigProvider && $discovered !== null && $discoveryCacheFile !== null) {
                $provider = new CachedAttributeConfigProvider(
                    inner: $provider(...),
                    cacheFile:    $cache->devAttributeConfig,
                    baselineFile: $discoveryCacheFile,
                );
            }

            $prepared[] = $provider;
        }

        return $prepared;
    }

    /**
     * @param list<callable(): array> $providers
     * @param array<string, mixed> $cachedDelta
     *
     * @return list<callable(): array>
     */
    private static function withCachedDelta(array $providers, array $cachedDelta): array
    {
        $deltaProvider = static fn (): array => $cachedDelta;

        foreach ($providers as $index => $provider) {
            if ($provider instanceof CachedAttributeConfigProvider || $provider instanceof AttributeConfigProvider) {
                array_splice($providers, $index + 1, 0, [$deltaProvider]);

                return $providers;
            }
        }

        array_unshift($providers, $deltaProvider);

        return $providers;
    }

    /**
     * @param list<string> $directories
     *
     * @return list<string>
     */
    private static function resolveDirectories(PathResolverInterface $paths, array $directories): array
    {
        $resolved = [];

        foreach ($directories as $directory) {
            $resolved[] = $paths->resolve($directory);
        }

        return $resolved;
    }
}
