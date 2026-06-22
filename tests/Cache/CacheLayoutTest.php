<?php

declare(strict_types=1);

use Componenta\App\Cache\CacheLayout;
use Componenta\App\ConfigKey;
use Componenta\Stdlib\PathResolver;
use Componenta\Config\Config;

function cacheLayoutPaths(): PathResolver
{
    return new PathResolver(str_replace('\\', '/', sys_get_temp_dir()));
}

describe('CacheLayout', function () {
    it('resolves the default cache layout from the project root', function () {
        $paths = cacheLayoutPaths();
        $cache = CacheLayout::bootstrap($paths);
        $root = $paths->baseDir;

        expect($cache->buildDir)->toBe($root . '/var/cache/build')
            ->and($cache->devDir)->toBe($root . '/var/cache/dev')
            ->and($cache->runtimeDir)->toBe($root . '/var/cache/runtime')
            ->and($cache->config)->toBe($root . '/var/cache/build/config.cache.php')
            ->and($cache->routes)->toBe($root . '/var/cache/build/routes.cache.php')
            ->and($cache->container)->toBe($root . '/var/cache/build/container.cache.php')
            ->and($cache->containerFactory)->toBe($root . '/var/cache/build/container.factory.php')
            ->and($cache->diPlans)->toBe($root . '/var/cache/build/di-plans.cache.php')
            ->and($cache->preload)->toBe($root . '/var/cache/build/preload.php')
            ->and($cache->devDiscovery)->toBe($root . '/var/cache/dev/discovery.dev.php');
    });

    it('keeps defaults as an alias for bootstrap cache layout', function () {
        $paths = cacheLayoutPaths();

        expect(CacheLayout::defaults($paths)->config)
            ->toBe(CacheLayout::bootstrap($paths)->config);
    });

    it('allows dev and runtime cache directories to be configured without making file names configurable', function () {
        $paths = cacheLayoutPaths();
        $cache = CacheLayout::fromConfig(new Config([
            'cache.build_dir' => 'runtime/cache/build',
            ConfigKey::CACHE_DEV_DIR => 'runtime/cache/dev',
            ConfigKey::CACHE_RUNTIME_DIR => 'runtime/cache/live',
            'cache.config_file' => 'custom-config.php',
        ]), $paths);
        $root = $paths->baseDir;

        expect($cache->buildDir)->toBe($root . '/var/cache/build')
            ->and($cache->devDir)->toBe($root . '/runtime/cache/dev')
            ->and($cache->runtimeDir)->toBe($root . '/runtime/cache/live')
            ->and($cache->config)->toBe($root . '/var/cache/build/config.cache.php')
            ->and($cache->devCompile)->toBe($root . '/runtime/cache/dev/compile.dev.php')
            ->and($cache->runtime('app'))->toBe($root . '/runtime/cache/live/app');
    });

    it('keeps absolute dev and runtime cache directories absolute', function () {
        $paths = cacheLayoutPaths();
        $root = $paths->baseDir;
        $cache = CacheLayout::fromConfig(new Config([
            ConfigKey::CACHE_DEV_DIR => $root . '/shared/dev-cache',
            ConfigKey::CACHE_RUNTIME_DIR => $root . '/shared/runtime-cache',
        ]), $paths);

        expect($cache->buildDir)->toBe($root . '/var/cache/build')
            ->and($cache->devDir)->toBe($root . '/shared/dev-cache')
            ->and($cache->runtimeDir)->toBe($root . '/shared/runtime-cache')
            ->and($cache->policies)->toBe($root . '/var/cache/build/policies.cache.php')
            ->and($cache->devAttributeConfig)->toBe($root . '/shared/dev-cache/attribute-config.dev.php');
    });

    it('allows bootstrap code to create a custom build layout explicitly', function () {
        $paths = cacheLayoutPaths();
        $root = $paths->baseDir;
        $cache = new CacheLayout(
            paths:            $paths,
            buildDirectory:   $root . '/shared/build-cache',
            devDirectory:     'var/cache/dev',
            runtimeDirectory: 'var/cache/runtime',
        );

        expect($cache->buildDir)->toBe($root . '/shared/build-cache')
            ->and($cache->config)->toBe($root . '/shared/build-cache/config.cache.php');
    });

    it('rejects empty cache directories from config', function (string $key) {
        expect(fn () => CacheLayout::fromConfig(new Config([
            $key => '',
        ]), cacheLayoutPaths()))->toThrow(RuntimeException::class, 'Cache directory path cannot be empty.');
    })->with([
        ConfigKey::CACHE_DEV_DIR,
        ConfigKey::CACHE_RUNTIME_DIR,
    ]);

    it('rejects an empty explicit build directory', function () {
        expect(fn () => new CacheLayout(
            paths:            cacheLayoutPaths(),
            buildDirectory:   '',
            devDirectory:     'var/cache/dev',
            runtimeDirectory: 'var/cache/runtime',
        ))->toThrow(RuntimeException::class, 'Cache directory path cannot be empty.');
    });
});
