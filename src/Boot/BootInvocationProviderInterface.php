<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

/**
 * Exposes the ordered list of discovered boot invocations.
 */
interface BootInvocationProviderInterface
{
    /** @var list<BootInvocation> */
    public array $bootInvocations { get; }
}
