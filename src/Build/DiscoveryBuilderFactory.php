<?php

declare(strict_types=1);

namespace Componenta\App\Build;

use Componenta\App\ConfigKey;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\ContainerValue;

final class DiscoveryBuilderFactory
{
    public function __invoke(ContainerValue $container): DiscoveryBuilder
    {
        return new DiscoveryBuilder(
            $container->get(ConfigKey::DISCOVERY_SOURCE, ClassIteratorInterface::class),
        );
    }
}
