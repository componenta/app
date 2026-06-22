<?php

declare(strict_types=1);

namespace Componenta\App\Config;

use RuntimeException;

use function Componenta\Config\config_merge;

final readonly class ComposerPackageConfigProvider
{
    public function __construct(
        private string $file,
    ) {}

    public function __invoke(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $providerClasses = require $this->file;

        if (!is_iterable($providerClasses)) {
            throw new RuntimeException(sprintf(
                'Composer package provider file "%s" must return an iterable list of provider classes.',
                $this->file,
            ));
        }

        $config = [];

        foreach ($providerClasses as $providerClass) {
            if (!is_string($providerClass) || !class_exists($providerClass)) {
                throw new RuntimeException(sprintf(
                    'Composer package provider must be an existing class-string, got %s (%s).',
                    get_debug_type($providerClass),
                    is_scalar($providerClass) ? (string) $providerClass : 'non-scalar',
                ));
            }

            $provider = new $providerClass();

            if (!is_callable($provider)) {
                throw new RuntimeException(sprintf(
                    'Composer package provider "%s" must be callable.',
                    $providerClass,
                ));
            }

            $providerConfig = $provider();

            if (!is_array($providerConfig)) {
                throw new RuntimeException(sprintf(
                    'Composer package provider "%s" must return an array, got %s.',
                    $providerClass,
                    get_debug_type($providerConfig),
                ));
            }

            if ($providerConfig !== []) {
                $config = config_merge($config, $providerConfig);
            }
        }

        return $config;
    }
}
