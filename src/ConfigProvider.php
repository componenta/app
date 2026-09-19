<?php

declare(strict_types=1);

namespace Componenta\App;

use Componenta\App\Boot\BootMethodInvocation;
use Componenta\App\Boot\BootMethodInvocationFactory;
use Componenta\App\Build\BootBuilder;
use Componenta\App\Build\BootBuilderFactory;
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
use Componenta\App\Build\DiscoveryBuilder;
use Componenta\App\Build\DiscoveryBuilderFactory;
use Componenta\App\Config\DiscoveryAwareConfigProviderInterface;
use Componenta\ClassFinder\ConfigKey as ClassFinderConfigKey;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\ConfigProvider as BaseConfigProvider;

final class ConfigProvider extends BaseConfigProvider implements DiscoveryAwareConfigProviderInterface
{
    public private(set) ?ClassIteratorInterface $discovered = null;

    public function withDiscovered(?ClassIteratorInterface $discovered): static
    {
        $provider = clone $this;
        $provider->discovered = $discovered;

        return $provider;
    }

    protected function getFactories(): array
    {
        return [
            ApplicationBuildOrchestrator::class => ApplicationBuildOrchestratorFactory::class,
            DiscoveryBuilder::class => DiscoveryBuilderFactory::class,
            BootBuilder::class => BootBuilderFactory::class,
            BootMethodInvocation::class => BootMethodInvocationFactory::class,
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
            ConfigKey::BUILDERS => $this->discovered !== null ? [DiscoveryBuilder::class, BootBuilder::class] : [],
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
