<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

/**
 * Immutable description of one `#[Boot]` method call.
 */
final readonly class BootInvocation
{
    /**
     * @param class-string $class
     * @param array<string|int, mixed> $params
     */
    public function __construct(
        public string $class,
        public string $method,
        public int $priority = 0,
        public array $params = [],
    ) {}
}
