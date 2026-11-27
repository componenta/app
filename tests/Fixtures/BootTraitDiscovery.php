<?php

declare(strict_types=1);

namespace Componenta\App\Tests\BootTraits;

use Componenta\App\Boot\Boot;

final class Trace
{
    public static array $calls = [];
}

trait Startup
{
    #[Boot(priority: 20)]
    public function instanceStart(): void
    {
        Trace::$calls[] = [static::class, 'instance'];
    }

    #[Boot(priority: 10)]
    public static function staticStart(): void
    {
        Trace::$calls[] = [static::class, 'static'];
    }
}

trait UnusedStartup
{
    #[Boot(priority: 30)]
    public static function unusedStart(): void
    {
        Trace::$calls[] = [static::class, 'unused'];
    }
}

final class FirstHost
{
    use Startup;
}

final class SecondHost
{
    use Startup;
}
