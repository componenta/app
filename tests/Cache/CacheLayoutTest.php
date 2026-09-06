<?php

declare(strict_types=1);

use Componenta\App\Cache\CacheLayout;
use Componenta\App\ConfigKey;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\Stdlib\PathResolver;

function cacheLayoutPaths(): PathResolver
{
    return new PathResolver(str_replace('\\', '/', sys_get_temp_dir()));
}

it('exposes only application artifact generations plus development and runtime cache roots', function (): void {
    $paths = cacheLayoutPaths();
    $cache = CacheLayout::bootstrap($paths);
    $root = $paths->baseDir;
    $generation = str_repeat('a', 64);

    expect($cache->buildDir)->toBe($root . '/var/cache/build')
        ->and($cache->current)->toBe($root . '/var/cache/build/current.json')
        ->and($cache->generations)->toBe($root . '/var/cache/build/generations')
        ->and($cache->generation($generation))->toBe($root . '/var/cache/build/generations/' . $generation)
        ->and($cache->manifest($generation))->toBe($root . '/var/cache/build/generations/' . $generation . '/manifest.json')
        ->and($cache->devDir)->toBe($root . '/var/cache/dev')
        ->and($cache->runtimeDir)->toBe($root . '/var/cache/runtime');
});

it('allows only development and runtime cache roots to come from runtime Config', function (): void {
    $paths = cacheLayoutPaths();
    $cache = CacheLayout::fromConfig(
        new Config([
            ConfigKey::CACHE_DEV_DIR => 'runtime/cache/dev',
            ConfigKey::CACHE_RUNTIME_DIR => 'runtime/cache/live',
        ], new Environment([])),
        $paths,
    );
    $root = $paths->baseDir;

    expect($cache->buildDir)->toBe($root . '/var/cache/build')
        ->and($cache->devDir)->toBe($root . '/runtime/cache/dev')
        ->and($cache->runtimeDir)->toBe($root . '/runtime/cache/live');
});

it('rejects invalid cache roots and generation ids', function (): void {
    expect(fn () => new CacheLayout(
        paths: cacheLayoutPaths(),
        buildDirectory: '',
        devDirectory: 'var/cache/dev',
        runtimeDirectory: 'var/cache/runtime',
    ))->toThrow(RuntimeException::class, 'Cache directory path cannot be empty.')
        ->and(fn () => CacheLayout::bootstrap(cacheLayoutPaths())->generation('../escape'))
        ->toThrow(RuntimeException::class, 'SHA-256');
});
