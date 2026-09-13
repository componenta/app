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

it('resolves application build, development and runtime cache paths', function (): void {
    $paths = cacheLayoutPaths();
    $cache = CacheLayout::bootstrap($paths);
    $root = $paths->baseDir;

    expect($cache->buildDir)->toBe($root . '/var/cache/build')
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

it('rejects empty cache roots', function (): void {
    expect(fn () => new CacheLayout(
        paths: cacheLayoutPaths(),
        buildDirectory: '',
        devDirectory: 'var/cache/dev',
        runtimeDirectory: 'var/cache/runtime',
    ))->toThrow(RuntimeException::class, 'Cache directory path cannot be empty.');
});
