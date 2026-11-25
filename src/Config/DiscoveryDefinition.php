<?php

declare(strict_types=1);

namespace Componenta\App\Config;

use InvalidArgumentException;

final readonly class DiscoveryDefinition implements DiscoveryDefinitionInterface
{
    /** @var non-empty-list<string> */
    public array $directories;

    /** @var list<string> */
    public array $exclude;

    /**
     * @param list<string> $directories Relative to PathResolverInterface::baseDir, or absolute.
     * @param list<string> $exclude     Patterns forwarded to the class finder.
     */
    public function __construct(array $directories, array $exclude = [])
    {
        if ($directories === []) {
            throw new InvalidArgumentException('Discovery directories cannot be empty.');
        }

        $this->directories = self::snapshot($directories, 'directories');
        $this->exclude = self::snapshot($exclude, 'exclude patterns');
    }

    /** @return list<string> */
    private static function snapshot(array $items, string $name): array
    {
        if (!array_is_list($items)) {
            throw new InvalidArgumentException(sprintf('Discovery %s must be a list.', $name));
        }

        $snapshot = [];
        foreach ($items as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(sprintf('Discovery %s must contain non-empty strings.', $name));
            }

            // Copy scalar values, not caller-owned PHP reference slots.
            $snapshot[] = $item;
        }

        return $snapshot;
    }
}
