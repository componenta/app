<?php

declare(strict_types=1);

namespace Componenta\App\Config;

interface ConfigDefinitionInterface
{
    /**
     * @var iterable<callable(): mixed|ComposerPackageConfigProvider>
     */
    public iterable $providers { get; }

    public ?DiscoveryDefinitionInterface $discovery { get; }
}
