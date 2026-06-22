<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

use Componenta\App\Scope;
use Componenta\Scope\Scopes;
use function Componenta\Config\env;

final class DateTimeBootloader implements BootloaderInterface
{
    use ScopedBootloaderSupport;

    public Scopes $scopes {
        get => Scopes::of(Scope::HTTP, Scope::CLI);
    }

    public function boot(BootContext $context): void
    {
        date_default_timezone_set(env('APP_TIMEZONE', 'UTC'));
    }

}
