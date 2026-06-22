<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

use Componenta\Scope\ScopedInterface;

/**
 * Contract for application bootloaders.
 *
 * Each bootloader declares its allowed scopes via {@see ScopedInterface}
 * and runs during application startup when its {@see self::supports()}
 * check passes. The bootloader
 * receives a {@see BootContext} - a narrow view of the runtime that
 * excludes the application's `run()` lifecycle entry-point.
 */
interface BootloaderInterface extends ScopedInterface
{
    public function boot(BootContext $context): void;

    public function supports(BootContext $context): bool;
}
