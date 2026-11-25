<?php

declare(strict_types=1);

namespace Componenta\App\Config;

use Attribute;

/** Marks an invokable class providing application configuration. */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsConfig
{
}
