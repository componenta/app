<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

use Componenta\App\Scope;
use Componenta\Scope\Scopes;
use InvalidArgumentException;

final class DateTimeBootloader implements BootloaderInterface
{
    use ScopedBootloaderSupport;

    public Scopes $scopes {
        get => Scopes::of(Scope::HTTP, Scope::CLI, Scope::WEBSOCKET);
    }

    public function boot(BootContext $context): void
    {
        $timezone = $context->container->config->environment->string('APP_TIMEZONE', 'UTC');
        $invalid = static fn (): InvalidArgumentException => new InvalidArgumentException(
            sprintf('Invalid APP_TIMEZONE "%s".', $timezone),
        );

        // PHP emits a notice and returns false for an unrecognized identifier.
        // Convert that diagnostic into a deterministic bootstrap failure.
        set_error_handler(static function () use ($invalid): never {
            throw $invalid();
        }, E_NOTICE | E_WARNING);
        try {
            if (!date_default_timezone_set($timezone)) {
                throw $invalid();
            }
        } finally {
            restore_error_handler();
        }
    }

}
