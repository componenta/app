<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Boot
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public int $priority = 0,
        public array $params = [],
    ) {}
}