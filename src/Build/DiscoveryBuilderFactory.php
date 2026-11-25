<?php

declare(strict_types=1);

namespace Componenta\App\Build;

use Componenta\App\ConfigKey;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\ContainerValue;
use Componenta\Stdlib\PathResolverInterface;

final class DiscoveryBuilderFactory
{
    public function __invoke(ContainerValue $container): DiscoveryBuilder
    {
        $paths = $container->get(PathResolverInterface::class, PathResolverInterface::class);

        return new DiscoveryBuilder(
            $container->get(ConfigKey::DISCOVERY_SOURCE, ClassIteratorInterface::class),
            $paths->resolve(ConfigKey::DISCOVERY_MAP_FILE),
            $container->get(ConfigKey::BUILD_GENERATION),
        );
    }
}
