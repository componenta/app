<?php

declare(strict_types=1);

namespace Componenta\App\Config;

use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\Exception\ConfigException;
use Componenta\Reflection\Reflection;
use Throwable;
use ReflectionClass;

final readonly class AttributeConfigProvider implements DiscoveryAwareConfigProviderInterface
{
    /** @var list<class-string>|null */
    private ?array $providerClasses;

    public function __construct(
        private ?ClassIteratorInterface $discovered = null,
        ?array $providerClasses = null,
    ) {
        // Retain structural membership/order without caller-owned reference slots.
        $this->providerClasses = $providerClasses === null
            ? null
            : array_map(static fn (mixed $class): mixed => $class, $providerClasses);
    }

    public function withDiscovered(?ClassIteratorInterface $discovered): static
    {
        return new self($discovered);
    }

    /** @param list<class-string> $classes Trusted structural metadata from the selected build. */
    public function withPreparedProviders(array $classes): self
    {
        return new self($this->discovered, $classes);
    }

    /** @return iterable<array<array-key, mixed>> */
    public function __invoke(): iterable
    {
        foreach ($this->providers() as $reflection) {
            try {
                $provider = $reflection->newInstance();
                if (!is_callable($provider)) {
                    throw new ConfigException('Config provider must be callable.');
                }

                $provided = $provider();
                if (is_array($provided)) {
                    yield $provided;
                    continue;
                }

                if (!is_iterable($provided)) {
                    throw new ConfigException(sprintf(
                        'Config provider must return array or iterable, %s given.',
                        get_debug_type($provided),
                    ));
                }

                // Iterating a lazy provider is part of invoking it. Keep
                // the same diagnostic boundary for eager and deferred errors.
                foreach ($provided as $contribution) {
                    if (!is_array($contribution)) {
                        throw new ConfigException(sprintf(
                            'Config contribution must be an array, %s given.',
                            get_debug_type($contribution),
                        ));
                    }

                    yield $contribution;
                }
            } catch (Throwable $e) {
                throw new ConfigException(
                    sprintf('Failed to load config from %s: %s', $reflection->getName(), $e->getMessage()),
                    previous: $e,
                );
            }
        }
    }

    /** @return iterable<ReflectionClass<object>> */
    private function providers(): iterable
    {
        if ($this->providerClasses !== null) {
            foreach ($this->providerClasses as $class) {
                yield new ReflectionClass($class);
            }
            return;
        }
        if ($this->discovered === null) {
            return;
        }

        $seen = [];
        foreach ($this->discovered as $class) {
            $key = strtolower(ltrim($class->fullyQualifiedName, '\\'));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (Reflection::hasMetadata($class->reflector, AsConfig::class)) {
                yield $class->reflector;
            }
        }
    }
}
