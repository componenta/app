<?php

declare(strict_types=1);

namespace Componenta\App\Config;

use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\Config;
use Componenta\Config\ConfigComposition;
use Componenta\Config\DependencyDefinitions;

final readonly class ConfigFactoryResult
{
    public Config $config;
    public DependencyDefinitions $dependencies;

    public function __construct(
        public ConfigComposition $composition,
        public ?ClassIteratorInterface $discovered = null,
    ) {
        $this->config = $composition->config;
        $this->dependencies = $composition->dependencies;
    }
}
