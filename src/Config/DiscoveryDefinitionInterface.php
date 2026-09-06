<?php

declare(strict_types=1);

namespace Componenta\App\Config;

use Componenta\App\Discovery\StaticDiscoveryExtractorInterface;

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

    /** @var iterable<StaticDiscoveryExtractorInterface> */
    public iterable $extractors { get; }
}
