<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

/**
 * Restricts a bootloader to the development environment.
 *
 * Provides a {@see BootloaderInterface::supports()} implementation that
 * returns `true` only when `APP_ENV` resolves to `development`. Opt in via
 * `use DevelopmentOnly;` on the concrete bootloader.
 */
trait DevelopmentOnly
{
    protected function supportsContext(BootContext $context): bool
    {
        return $context->container->config->environment?->match('APP_ENV', 'development') ?? false;
    }
}
