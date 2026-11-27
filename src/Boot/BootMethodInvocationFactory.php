<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

use Componenta\App\Build\PreparedBuild;
use Componenta\App\ConfigKey;
use Componenta\Config\ContainerValue;

final class BootMethodInvocationFactory
{
    public function __invoke(ContainerValue $container): BootMethodInvocation
    {
        $map = null;
        if ($container->has(ConfigKey::PREPARED_BUILD)) {
            $map = $container->get(ConfigKey::PREPARED_BUILD, PreparedBuild::class)->read(ConfigKey::BOOT_ARTIFACT);
        }
        return new BootMethodInvocation($container->get(BootInvocationRunnerInterface::class, BootInvocationRunnerInterface::class), $map);
    }
}
