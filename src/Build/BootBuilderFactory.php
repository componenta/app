<?php

declare(strict_types=1);

namespace Componenta\App\Build;

use Componenta\App\ConfigKey;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\ContainerValue;
use Componenta\Stdlib\PathResolverInterface;

final class BootBuilderFactory
{
    public function __invoke(ContainerValue $container): BootBuilder
    {
        return new BootBuilder(
            $container->get(ConfigKey::DISCOVERY_SOURCE, ClassIteratorInterface::class),
            $container->get(PathResolverInterface::class, PathResolverInterface::class)->resolve(ConfigKey::BOOT_MAP_FILE),
        );
    }
}
