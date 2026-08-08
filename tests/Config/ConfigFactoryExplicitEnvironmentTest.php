<?php

declare(strict_types=1);

use Componenta\App\Config\ConfigDefinition;
use Componenta\App\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\Stdlib\PathResolver;

it('uses an explicit environment instead of reloading the project env file', function (): void {
    $root = str_replace('\\', '/', sys_get_temp_dir())
        . '/componenta_config_factory_explicit_environment_'
        . bin2hex(random_bytes(4));

    if (!mkdir($root, 0o755, recursive: true) && !is_dir($root)) {
        throw new RuntimeException('Failed to create config factory test runtime.');
    }

    file_put_contents($root . '/.env', "APP_ENV=production\n");
    $definitionLoaded = false;

    try {
        $result = ConfigFactory::create(
            paths: new PathResolver($root),
            definition: function () use (&$definitionLoaded): ConfigDefinition {
                $definitionLoaded = true;

                return new ConfigDefinition([
                    static fn (): array => ['from' => 'source'],
                ]);
            },
            environment: new Environment(['APP_ENV' => 'development']),
        );

        expect($definitionLoaded)->toBeTrue()
            ->and($result->config->get('from'))->toBe('source')
            ->and($result->config->environment?->string('APP_ENV'))->toBe('development');
    } finally {
        unlink($root . '/.env');
        rmdir($root);
    }
});
