<?php

declare(strict_types=1);

namespace Componenta\App\Discovery;

use Componenta\ClassFinder\ClassIteratorInterface;

/**
 * Package-owned pure extractor for one static semantic map.
 *
 * Implementations may inspect source discovery metadata but must not invoke
 * application config providers, handlers, controllers, bootloaders or other
 * runtime code. Returned values must be JSON-encodable data.
 */
interface StaticDiscoveryExtractorInterface
{
    public function key(): string;

    /** @return array<array-key, mixed> Config contribution consumed by the normal runtime path. */
    public function extract(ClassIteratorInterface $classes): array;
}
