<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

use Componenta\App\ConfigKey;
use Componenta\App\Discovery\Compile\CompileCache;
use Componenta\App\Discovery\Compile\CompileCacheContributorInterface;
use Componenta\App\Discovery\Compile\DiPlanBuilder;
use Componenta\App\Discovery\ListenerCompiler;
use Componenta\App\Discovery\ListenerRestorer;
use Componenta\App\Scope;
use Componenta\Scope\Scopes;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\ClassFinder\ClassListenerNotifier;
use Componenta\Config\ContainerValue;
use Componenta\DI\Compile\PlanCompiler;
use Componenta\DI\Compile\PlanDispatcher;
use Componenta\DI\ConfigKey as DiConfigKey;
use RuntimeException;
use function Componenta\Config\config_merge;

/**
 * Drives class-listener population once per request.
 *
 * Dev cold: `Discovery` scanned and handed a {@see ClassIteratorInterface}
 * into the container. We fan the iterator through {@see ClassListenerNotifier},
 * then - if a {@see CompileCache} is available - snapshot the full compile
 * output (discovery target map + DI plans) so the next request takes the
 * warm branch.
 *
 * Dev warm / prod: `ListenerRestorer::CACHE_KEY` is already in config
 * (injected by `config.php` from the cache file, or baked into
 * `config.cache.php` by the CLI compile command). Replay it - no
 * filesystem scan, targeted per-listener reflection only on the subset
 * each listener claims via `#[ListenTo]`. Prod skips `#[DevOnly]`
 * listeners entirely; dev restores them too so attribute-based locators
 * stay functional when their Plain counterparts don't have their maps
 * yet.
 */
final class ClassDiscoveryBootloader implements BootloaderInterface
{
    use ScopedBootloaderSupport;

    public Scopes $scopes {
        get => Scopes::of(Scope::HTTP, Scope::CLI, Scope::WEBSOCKET);
    }

    public function boot(BootContext $context): void
    {
        $container = $context->container;
        $config = $container->config;

        if (($config->has(ListenerRestorer::CACHE_KEY) || $config->has(ListenerRestorer::CACHE_FILE_KEY))
            && $container->has(ListenerRestorer::class)
        ) {
            $isDev = $config->environment?->match('APP_ENV', 'production') === false;
            $restorer = $container->get(ListenerRestorer::class, ListenerRestorer::class);

            if ($restorer->hasCache()) {
                $restorer->restore(includeDevOnly: $isDev);

                return;
            }
        }

        if (!$container->has(ClassIteratorInterface::class)) {
            return;
        }

        $iterator = $container->get(ClassIteratorInterface::class, ClassIteratorInterface::class);
        $container->get(ClassListenerNotifier::class, ClassListenerNotifier::class)->notify($iterator);

        if ($container->has(CompileCache::class)) {
            $this->persistCompileDelta($container, $iterator);
        }
    }

    private function persistCompileDelta(ContainerValue $container, ClassIteratorInterface $iterator): void
    {
        $discoveryCache = $container->get(ListenerCompiler::class, ListenerCompiler::class)->compile($iterator);

        $diPlanBuilder = $container->get(DiPlanBuilder::class, DiPlanBuilder::class);
        $diPlans = $diPlanBuilder->compile($discoveryCache['classes']);
        $dispatcherMap = $diPlanBuilder->dispatcherMap();

        $delta = [
            ListenerRestorer::CACHE_KEY => $discoveryCache,
            DiConfigKey::DEPENDENCIES   => [
                PlanCompiler::CONFIG_KEY => $diPlans,
                PlanDispatcher::CONFIG_KEY => $dispatcherMap,
            ],
        ];

        foreach ($this->compileContributors($container) as $contributor) {
            $delta = config_merge($delta, $contributor->compile($discoveryCache['classes']));
        }

        $container->get(CompileCache::class, CompileCache::class)->persist($delta);
    }

    /**
     * @return list<CompileCacheContributorInterface>
     */
    private function compileContributors(ContainerValue $container): array
    {
        $entries = $container->config->get(ConfigKey::COMPILE_CACHE_CONTRIBUTORS, []);

        if (!is_array($entries)) {
            throw new RuntimeException(sprintf('%s config value must be an array.', ConfigKey::COMPILE_CACHE_CONTRIBUTORS));
        }

        $contributors = [];

        foreach ($entries as $entry) {
            $contributor = is_string($entry) ? $container->get($entry) : $entry;

            if (!$contributor instanceof CompileCacheContributorInterface) {
                throw new RuntimeException(sprintf(
                    'Compile cache contributor must implement %s.',
                    CompileCacheContributorInterface::class,
                ));
            }

            $contributors[] = $contributor;
        }

        return $contributors;
    }
}
