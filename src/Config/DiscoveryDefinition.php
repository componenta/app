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
        self::validate($this->directories, 'directories');
        self::validate($this->exclude, 'exclude patterns');
    }

    private static function validate(array $items, string $name): void
    {
        if (!array_is_list($items)) {
            throw new InvalidArgumentException(sprintf('Discovery %s must be a list.', $name));
        }
        foreach ($items as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(sprintf('Discovery %s must contain non-empty strings.', $name));
            }
        }
    }
}
