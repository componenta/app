<?php

declare(strict_types=1);

namespace Componenta\App\Build;

use Componenta\App\ConfigKey;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\ContainerValue;

final class ConfigProviderBuilderFactory
{
    public function __invoke(ContainerValue $container): ConfigProviderBuilder
    {
        return new ConfigProviderBuilder($container->get(ConfigKey::DISCOVERY_SOURCE, ClassIteratorInterface::class));
    }
}
