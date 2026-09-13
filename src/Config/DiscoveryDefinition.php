<?php

declare(strict_types=1);

namespace Componenta\App\Config;

use InvalidArgumentException;

final readonly class DiscoveryDefinition implements DiscoveryDefinitionInterface
{
    /**
     * @param list<string> $directories Relative to PathResolverInterface::baseDir, or absolute.
     * @param list<string> $exclude     Patterns forwarded to the class finder.
     */
    public function __construct(
        public array $directories,
        public array $exclude = [],
    ) {
        if ($this->directories === []) {
            throw new InvalidArgumentException('Discovery directories cannot be empty.');
        }
    }
}
