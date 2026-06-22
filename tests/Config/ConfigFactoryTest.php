<?php

declare(strict_types=1);

use Componenta\App\Config\ConfigDefinition;
use Componenta\App\Config\ConfigFactory;
use Componenta\App\Config\DiscoveryDefinition;
use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\DI\ConfigKey;
use Componenta\Stdlib\PathResolver;

function configFactoryRuntimeRoot(): string
{
    $root = str_replace('\\', '/', sys_get_temp_dir()) . '/componenta_config_factory_' . bin2hex(random_bytes(4));

    if (!mkdir($root . '/var/cache/build', 0o755, recursive: true) && !is_dir($root . '/var/cache/build')) {
        throw new RuntimeException('Failed to create config factory test runtime.');
    }

    return $root;
}

function removeConfigFactoryRuntimeRoot(string $root): void
{
    if (!is_dir($root)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($root);
}

describe('ConfigFactory', function () {
    it('does not load the config definition in production', function () {
        $root = configFactoryRuntimeRoot();
        file_put_contents($root . '/.env', "APP_ENV=production\n");
        file_put_contents(
            $root . '/var/cache/build/config.cache.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn ['config' => ['from' => 'cache'], 'environment' => ['APP_ENV' => 'compiled']];\n",
        );
        $called = false;

        try {
            $result = ConfigFactory::create(
                new PathResolver($root),
                function () use (&$called): never {
                    $called = true;

                    throw new RuntimeException('Definition loader must not run in production.');
                },
            );

            expect($called)->toBeFalse()
                ->and($result->config->get('from'))->toBe('cache')
                ->and($result->config->environment?->string('APP_ENV'))->toBe('production')
                ->and($result->discovered)->toBeNull();
        } finally {
            removeConfigFactoryRuntimeRoot($root);
        }
    });

    it('loads the config definition in development', function () {
        $root = configFactoryRuntimeRoot();
        file_put_contents($root . '/.env', "APP_ENV=development\n");
        $called = false;

        try {
            $result = ConfigFactory::create(
                new PathResolver($root),
                function () use (&$called): ConfigDefinition {
                    $called = true;

                    return new ConfigDefinition(
                        providers: [
                            static fn (): array => ['from' => 'definition'],
                        ],
                    );
                },
            );

            expect($called)->toBeTrue()
                ->and($result->config->get('from'))->toBe('definition')
                ->and($result->discovered)->toBeNull();
        } finally {
            removeConfigFactoryRuntimeRoot($root);
        }
    });

    it('loads discovery without injecting compile cache into generated config', function () {
        $root = configFactoryRuntimeRoot();
        mkdir($root . '/src', recursive: true);
        file_put_contents($root . '/.env', "APP_ENV=development\n");
        file_put_contents($root . '/src/Example.php', "<?php\n\ndeclare(strict_types=1);\n\nfinal class Example {}\n");

        try {
            $result = ConfigFactory::create(
                new PathResolver($root),
                static fn (): ConfigDefinition => new ConfigDefinition(
                    providers: [
                        static fn (): array => [],
                    ],
                    discovery: new DiscoveryDefinition(
                        directories: ['src'],
                    ),
                ),
            );

            $dependencies = $result->config->get(ConfigKey::DEPENDENCIES, []);
            $services = $dependencies[ConfigKey::SERVICES] ?? [];

            expect(is_file($root . '/var/cache/dev/discovery.dev.php'))->toBeTrue()
                ->and($services)->not->toHaveKey(Componenta\App\Discovery\Compile\CompileCache::class);
        } finally {
            removeConfigFactoryRuntimeRoot($root);
        }
    });

    it('uses configured development cache directories before discovery runs', function () {
        $root = configFactoryRuntimeRoot();
        mkdir($root . '/src', recursive: true);
        file_put_contents($root . '/.env', "APP_ENV=development\n");
        file_put_contents($root . '/src/Example.php', "<?php\n\ndeclare(strict_types=1);\n\nfinal class Example {}\n");

        try {
            $result = ConfigFactory::create(
                new PathResolver($root),
                static fn (): ConfigDefinition => new ConfigDefinition(
                    providers: [
                        static fn (): array => [
                            AppConfigKey::CACHE_DEV_DIR => 'runtime/cache/dev',
                            AppConfigKey::CACHE_RUNTIME_DIR => 'runtime/cache/live',
                        ],
                    ],
                    discovery: new DiscoveryDefinition(
                        directories: ['src'],
                    ),
                ),
            );

            expect(is_file($root . '/runtime/cache/dev/discovery.dev.php'))->toBeTrue()
                ->and(is_file($root . '/var/cache/dev/discovery.dev.php'))->toBeFalse()
                ->and(is_file($root . '/var/cache/dev/compile.dev.php'))->toBeFalse();
        } finally {
            removeConfigFactoryRuntimeRoot($root);
        }
    });
});
