<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

use Componenta\App\Build\PhpMapFile;
use Componenta\App\ConfigKey;
use Componenta\Config\ContainerValue;
use Componenta\Stdlib\PathResolverInterface;

final class BootMethodInvocationFactory
{
    public function __invoke(ContainerValue $container): BootMethodInvocation
    {
        $map = null;
        if ($container->has(ConfigKey::DISCOVERY_PREPARED) && $container->get(ConfigKey::DISCOVERY_PREPARED) === true) {
            $map = PhpMapFile::read($container->get(PathResolverInterface::class, PathResolverInterface::class)->resolve(ConfigKey::BOOT_MAP_FILE));
        }
        return new BootMethodInvocation($container->get(BootInvocationRunnerInterface::class, BootInvocationRunnerInterface::class), $map);
    }
}
