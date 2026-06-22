<?php

declare(strict_types=1);

namespace Componenta\App\Config;

interface DiscoveryDefinitionInterface
{
    /**
     * @var list<string>
     */
    public array $directories { get; }

    /**
     * @var list<string>
     */
    public array $exclude { get; }

}
