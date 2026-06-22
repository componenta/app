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
use Componenta\App\Boot\Compile\BootInvocationCompiler;
use Componenta\App\Boot\CompiledBootInvocationBootloader;
use Componenta\App\Boot\DateTimeBootloader;
use Componenta\App\Boot\ClassDiscoveryBootloader;
use Componenta\App\Cache\CacheLayout;
use Componenta\App\Discovery\ListenerCompiler;
use Componenta\App\Discovery\ListenerRestorer;
use Componenta\App\Discovery\Compile\CompileCache;
use Componenta\App\Discovery\Compile\DiPlanBuilder;
use Componenta\App\Discovery\Compile\DiscoveryCompiler;
use Componenta\App\Discovery\Compile\DiscoveryCompilerFactory;
use Componenta\ClassFinder\Compile\ConfigKey as CompileConfigKey;
use Componenta\ClassFinder\ConfigKey as ClassFinderConfigKey;
use Componenta\Config\ContainerValue;
use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\Stdlib\PathResolverInterface;

final class ConfigProvider extends BaseConfigProvider
{
    protected function getFactories(): array
    {
        return [
            BootTargetFactory::class => static fn (ContainerValue $container): BootTargetFactory => new BootTargetFactory($container),
            DiscoveryCompiler::class => DiscoveryCompilerFactory::class,
            CompileCache::class => static function (ContainerValue $container): CompileCache {
                $cache = CacheLayout::fromConfig(
                    $container->config,
                    $container->get(PathResolverInterface::class, PathResolverInterface::class),
                );

                return new CompileCache(
                    cacheFile:    $cache->devCompile,
                    baselineFile: $cache->devDiscovery,
                );
            },
        ];
    }

    protected function getAutowires(): array
    {
        return [
            AppFactory::class,
            BootMethodInvocation::class,
            BootInvocationRunner::class,
            BootInvocationCompiler::class,
            BootloaderProvider::class,
            CompiledBootInvocationBootloader::class,
            DateTimeBootloader::class,
            DiPlanBuilder::class,
            ListenerCompiler::class,
            ListenerRestorer::class,
            ClassDiscoveryBootloader::class,
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
                CompiledBootInvocationBootloader::class,
            ],
            ClassFinderConfigKey::LISTENERS => [
                BootMethodInvocation::class,
            ],
            CompileConfigKey::LISTENER_COMPILERS => [
                BootInvocationCompiler::class,
            ],
        ];
    }
}
