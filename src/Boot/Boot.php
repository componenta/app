<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Boot
{
    /** @var array<string|int, mixed> */
    public array $params;

    /**
     * @param array<string|int, mixed> $params
     */
    public function __construct(
        public int $priority = 0,
        array $params = [],
    ) {
        $snapshot = [];
        foreach ($params as $key => $value) {
            $snapshot[$key] = $value;
        }
        $this->params = $snapshot;
    }
}
