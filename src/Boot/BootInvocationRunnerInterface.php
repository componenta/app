<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

/**
 * Executes boot invocations after their metadata has been discovered or restored.
 */
interface BootInvocationRunnerInterface
{
    /**
     * @param iterable<BootInvocation> $invocations
     */
    public function run(iterable $invocations): void;
}
