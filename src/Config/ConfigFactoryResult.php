<?php

declare(strict_types=1);

namespace Componenta\App\Config;

use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\Config;

final readonly class ConfigFactoryResult
{
    public function __construct(
        public Config $config,
        public ?ClassIteratorInterface $discovered = null,
    ) {}
}
