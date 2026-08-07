<?php

declare(strict_types=1);

use Componenta\App\ContainerCacheMode;
use Componenta\App\ContainerFactory;
use Componenta\App\ContainerFactoryOptions;
use Componenta\Config\Config;
use Componenta\DI\ConfigKey as DiConfigKey;
use Componenta\Stdlib\PathResolver;
use Componenta\Stdlib\PathResolverInterface;

it('falls back to source dependencies when the optional container cache is corrupt', function (): void {
    $root = sys_get_temp_dir() . '/componenta-app-container-factory-' . bin2hex(random_bytes(8));
    $buildDirectory = $root . '/var/cache/build';
    $cacheFile = $buildDirectory . '/container.cache.php';

    mkdir($buildDirectory, recursive: true);
    file_put_contents($cacheFile, "<?php\nthrow new \\RuntimeException('corrupt cache');\n");

    try {
        $paths = new PathResolver($root);
        $config = new Config([
            DiConfigKey::DEPENDENCIES => [],
        ]);

        $container = ContainerFactory::create(
            paths: $paths,
            config: $config,
            options: new ContainerFactoryOptions(ContainerCacheMode::CacheFile),
        );

        expect($container->get(PathResolverInterface::class))->toBe($paths);
    } finally {
        if (is_file($cacheFile)) {
            unlink($cacheFile);
        }

        rmdir($buildDirectory);
        rmdir(dirname($buildDirectory));
        rmdir(dirname($buildDirectory, 2));
        rmdir($root);
    }
});
