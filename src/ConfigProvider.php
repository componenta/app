<?php

declare(strict_types=1);

namespace Componenta\App;

use Componenta\App\Boot\BootMethodInvocation;
use Componenta\App\Boot\BootInvocationRunner;
use Componenta\App\Boot\BootInvocationRunnerInterface;
use Componenta\App\Boot\BootloaderProvider;
use Componenta\App\Boot\BootloaderProviderInterface;
use Componenta\App\Boot\BootTargetFactory;
use Componenta\App\Boot\BootTargetFactoryInterface;
use Componenta\App\Boot\ClassDiscoveryBootloader;
use Componenta\App\Boot\DateTimeBootloader;
use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\App\Build\ApplicationBuildOrchestratorFactory;
use Componenta\ClassFinder\ConfigKey as ClassFinderConfigKey;
use Componenta\Config\ConfigProvider as BaseConfigProvider;

final class ConfigProvider extends BaseConfigProvider
{
    protected function getFactories(): array
    {
        return [
            ApplicationBuildOrchestrator::class => ApplicationBuildOrchestratorFactory::class,
        ];
    }

    protected function getAliases(): array
    {
        return [
            AppFactoryInterface::class => AppFactory::class,
            BootTargetFactoryInterface::class => BootTargetFactory::class,
            BootInvocationRunnerInterface::class => BootInvocationRunner::class,
            BootloaderProviderInterface::class => BootloaderProvider::class,
        ];
    }

    protected function getConfig(): array
    {
        return [
            ConfigKey::BOOTLOADERS => [
                DateTimeBootloader::class,
                ClassDiscoveryBootloader::class,
            ],
            ClassFinderConfigKey::LISTENERS => [
                BootMethodInvocation::class,
            ],
        ];
    }
}
