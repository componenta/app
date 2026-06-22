<?php

declare(strict_types=1);

namespace Componenta\App;

use Componenta\App\Cache\CacheLayout;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\Stdlib\PathResolverInterface;
use RuntimeException;
use Throwable;

final class ContainerFactory
{
    private function __construct() {}

    public static function create(
        PathResolverInterface $paths,
        Config $config,
        ?iterable $discovered = null,
        ?ContainerFactoryOptions $options = null,
    ): Container {
        $options ??= new ContainerFactoryOptions();
        $cache = CacheLayout::fromConfig($config, $paths);
        $containerCache = $cache->container;
        $containerFactoryCache = $cache->containerFactory;
        $hasConfigDependencies = $config->has(ConfigKey::DEPENDENCIES);

        if ($options->cacheMode === ContainerCacheMode::FactoryFile) {
            $container = self::buildFromFactory($containerFactoryCache, $config, $paths, $discovered);

            if ($container === null) {
                throw new RuntimeException(sprintf('Container factory cache is not callable: %s', $containerFactoryCache));
            }

            return $container;
        }

        if ($options->cacheMode === ContainerCacheMode::Auto && self::isProduction($config) && is_file($containerFactoryCache)) {
            try {
                $container = self::buildFromFactory($containerFactoryCache, $config, $paths, $discovered);

                if ($container !== null) {
                    return $container;
                }
            } catch (Throwable $e) {
                if (!$hasConfigDependencies && !is_file($containerCache)) {
                    throw $e;
                }
            }
        }

        $builder = self::builderFromCache(
            options: $options,
            config: $config,
            containerCache: $containerCache,
            hasConfigDependencies: $hasConfigDependencies,
        ) ?? ContainerBuilder::configure($config);

        $builder->addService(PathResolverInterface::class, $paths);

        if ($discovered !== null) {
            $builder->addService(ClassIteratorInterface::class, $discovered);
        }

        return $builder->build();
    }

    private static function builderFromCache(
        ContainerFactoryOptions $options,
        Config $config,
        string $containerCache,
        bool $hasConfigDependencies,
    ): ?ContainerBuilder {
        if ($options->cacheMode === ContainerCacheMode::Disabled) {
            return null;
        }

        $shouldReadCache = match ($options->cacheMode) {
            ContainerCacheMode::Auto => self::isProduction($config) || !$hasConfigDependencies,
            ContainerCacheMode::CacheFile, ContainerCacheMode::RequireCache => true,
            ContainerCacheMode::Disabled, ContainerCacheMode::FactoryFile => false,
        };

        if (!$shouldReadCache) {
            return null;
        }

        if (is_file($containerCache)) {
            try {
                $cached = require $containerCache;

                if (is_array($cached)) {
                    return ContainerBuilder::configureFromCache($config, $cached, dirname($containerCache));
                }
            } catch (Throwable $e) {
                if (!$hasConfigDependencies || $options->cacheMode === ContainerCacheMode::RequireCache) {
                    throw $e;
                }
            }
        }

        if (!$hasConfigDependencies || $options->cacheMode === ContainerCacheMode::RequireCache) {
            throw new RuntimeException(sprintf(
                'Container cache is required but unavailable: %s',
                $containerCache,
            ));
        }

        return null;
    }

    private static function buildFromFactory(
        string $containerFactoryCache,
        Config $config,
        PathResolverInterface $paths,
        ?iterable $discovered,
    ): ?Container {
        $factory = require $containerFactoryCache;

        if (!is_callable($factory)) {
            return null;
        }

        $container = $factory($config);

        if (!$container instanceof Container) {
            throw new RuntimeException(sprintf(
                'Container factory must return %s, got %s.',
                Container::class,
                get_debug_type($container),
            ));
        }

        $container->set(PathResolverInterface::class, $paths);

        if ($discovered !== null) {
            $container->set(ClassIteratorInterface::class, $discovered);
        }

        return $container;
    }

    private static function isProduction(Config $config): bool
    {
        return $config->environment?->match('APP_ENV', 'production') ?? false;
    }
}
