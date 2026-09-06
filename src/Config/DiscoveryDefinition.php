<?php

declare(strict_types=1);

namespace Componenta\App\Config;

use Componenta\App\Discovery\StaticDiscoveryExtractorInterface;
use InvalidArgumentException;

final readonly class DiscoveryDefinition implements DiscoveryDefinitionInterface
{
    /**
     * @param list<string> $directories Relative to PathResolverInterface::baseDir, or absolute.
     * @param list<string> $exclude     Patterns forwarded to the class finder.
     * @param iterable<StaticDiscoveryExtractorInterface> $extractors
     */
    public function __construct(
        public array $directories,
        public array $exclude = [],
        public iterable $extractors = [],
    ) {
        if ($this->directories === []) {
            throw new InvalidArgumentException('Discovery directories cannot be empty.');
        }
    }
}
