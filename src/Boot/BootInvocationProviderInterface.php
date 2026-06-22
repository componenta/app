<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

/**
 * Exposes discovered boot invocations to build-time compilers.
 */
interface BootInvocationProviderInterface
{
    /** @var list<BootInvocation> */
    public array $bootInvocations { get; }
}
