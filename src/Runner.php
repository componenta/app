<?php

declare(strict_types=1);

namespace Componenta\App;

use Componenta\App\Boot\BootContext;
use Componenta\App\Boot\BootTargetFactoryInterface;
use Componenta\App\Boot\BootloaderProviderInterface;
use Componenta\Config\ContainerValue;
use Componenta\Scope\ScopeInterface;

/**
 * Single entry point for running the application.
 *
 * The caller (entry script) supplies the active {@see ScopeInterface},
 * and the container value carrying the DI container plus merged config.
 * Runner asks the
 * {@see AppFactoryInterface} for a matching {@see AppInterface}, wraps it
 * into a scope-specific boot target, then drives every eligible bootloader
 * through its `boot()` contract before handing control to the app itself.
 *
 * Bootloader selection is delegated entirely to
 * {@see BootloaderProviderInterface} - this class stays free of config
 * shape knowledge and filter logic.
 */
final class Runner
{
    public static function run(ScopeInterface $scope, ContainerValue $container): ?int
    {
        $app = $container->get(AppFactoryInterface::class, AppFactoryInterface::class)
            ->createApp($scope, $container);

        $target = $container->get(BootTargetFactoryInterface::class, BootTargetFactoryInterface::class)
            ->create($app, $scope);

        $provider = $container->get(BootloaderProviderInterface::class, BootloaderProviderInterface::class);
        $context = new BootContext($container, $scope, $target);

        foreach ($provider->provideFor($context) as $bootloader) {
            $bootloader->boot($context);
        }

        return $app->run();
    }
}
