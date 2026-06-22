<?php

declare(strict_types=1);

namespace Componenta\App\Config;

use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\Exception\ConfigException;
use Componenta\Reflection\Reflection;
use Throwable;

use function Componenta\Config\config_merge;

final readonly class AttributeConfigProvider implements DiscoveryAwareConfigProviderInterface
{
    public function __construct(
        public ?ClassIteratorInterface $discovered = null,
    ) {}

    public function withDiscovered(?ClassIteratorInterface $discovered): static
    {
        return new self($discovered);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ConfigException
     */
    public function __invoke(): array
    {
        if ($this->discovered === null) {
            return [];
        }

        $result = [];

        foreach ($this->discovered as $class) {
            if (!Reflection::hasMetadata($class->reflector, AsConfig::class)) {
                continue;
            }

            try {
                $data = $class->reflector->newInstance()();
            } catch (Throwable $e) {
                throw new ConfigException(
                    sprintf('Failed to load config from %s: %s', $class->reflector->getName(), $e->getMessage()),
                );
            }

            if (!is_array($data)) {
                if (is_iterable($data)) {
                    $data = iterator_to_array($data);
                } else {
                    throw new ConfigException(sprintf(
                        'Config provider %s must return array or iterable, %s given',
                        $class->reflector->getName(),
                        get_debug_type($data),
                    ));
                }
            }

            if ($data === []) {
                continue;
            }

            $result = config_merge($result, $data);
        }

        return $result;
    }
}
