<?php

declare(strict_types=1);

namespace Componenta\App\Config;

use RuntimeException;

/**
 * Source descriptor for Composer's generated ordered package-provider list.
 *
 * Extraction never instantiates or invokes a provider. App ConfigFactory
 * materializes the verified/source class list and remains the sole owner of
 * provider invocation and merge order.
 */
final readonly class ComposerPackageConfigProvider
{
    public function __construct(private string $file)
    {
    }

    /** @return list<class-string> */
    public function classes(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $classes = require $this->file;
        if (!is_array($classes) || !self::isClassList($classes)) {
            throw new RuntimeException(sprintf(
                'Composer package provider file "%s" must return an iterable list of provider classes.',
                $this->file,
            ));
        }

        return $classes;
    }

    /**
     * @param list<class-string> $classes
     * @return iterable<callable(): mixed>
     */
    public function materialize(array $classes): iterable
    {
        foreach ($classes as $providerClass) {
            if (!class_exists($providerClass)) {
                throw new RuntimeException(sprintf(
                    'Composer package provider must be an existing class-string, got "%s".',
                    $providerClass,
                ));
            }

            $provider = new $providerClass();
            if (!is_callable($provider)) {
                throw new RuntimeException(sprintf(
                    'Composer package provider "%s" must be callable.',
                    $providerClass,
                ));
            }

            yield $provider;
        }
    }

    /** @phpstan-assert-if-true list<class-string> $value */
    public static function isClassList(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $class) {
            if (!is_string($class) || $class === '') {
                return false;
            }
        }

        return true;
    }
}
