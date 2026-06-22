<?php

declare(strict_types=1);

namespace Componenta\App\Config;

final readonly class ConfigDefinition implements ConfigDefinitionInterface
{
    /**
     * @param iterable<callable(): array> $providers
     */
    public function __construct(
        public iterable $providers,
        public ?DiscoveryDefinitionInterface $discovery = null,
    ) {}
}
