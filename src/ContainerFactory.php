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
        $hasConfigDependencies = $config->has(ConfigKey::DEPENDENCIES);

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
            ContainerCacheMode::Disabled => false,
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

    private static function isProduction(Config $config): bool
    {
        return $config->environment?->match('APP_ENV', 'production') ?? false;
    }
}
