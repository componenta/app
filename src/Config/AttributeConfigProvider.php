<?php

declare(strict_types=1);

namespace Componenta\App\Config;

use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\Exception\ConfigException;
use Componenta\Reflection\Reflection;
use Throwable;

final readonly class AttributeConfigProvider implements DiscoveryAwareConfigProviderInterface
{
    public function __construct(
        public ?ClassIteratorInterface $discovered = null,
    ) {
    }

    public function withDiscovered(?ClassIteratorInterface $discovered): static
    {
        return new self($discovered);
    }

    /** @return iterable<array<array-key, mixed>> */
    public function __invoke(): iterable
    {
        if ($this->discovered === null) {
            return;
        }

        foreach ($this->discovered as $class) {
            if (!Reflection::hasMetadata($class->reflector, AsConfig::class)) {
                continue;
            }

            try {
                $provider = $class->reflector->newInstance();
                if (!is_callable($provider)) {
                    throw new ConfigException(sprintf(
                        'Config provider %s must be callable.',
                        $class->reflector->getName(),
                    ));
                }

                $provided = $provider();
            } catch (Throwable $e) {
                throw new ConfigException(
                    sprintf('Failed to load config from %s: %s', $class->reflector->getName(), $e->getMessage()),
                    previous: $e,
                );
            }

            if (is_array($provided)) {
                yield $provided;
                continue;
            }

            if (!is_iterable($provided)) {
                throw new ConfigException(sprintf(
                    'Config provider %s must return array or iterable, %s given',
                    $class->reflector->getName(),
                    get_debug_type($provided),
                ));
            }

            foreach ($provided as $contribution) {
                if (!is_array($contribution)) {
                    throw new ConfigException(sprintf(
                        'Config contribution from %s must be an array, %s given',
                        $class->reflector->getName(),
                        get_debug_type($contribution),
                    ));
                }

                yield $contribution;
            }
        }
    }
}
