<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

use Componenta\App\Scope;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\ClassFinder\ClassListenerNotifier;
use Componenta\Scope\Scopes;

/**
 * Feeds the same semantic listeners from either source discovery or a
 * verified production snapshot. Source selection happens in ConfigFactory.
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
        if (!$container->has(ClassIteratorInterface::class)) {
            return;
        }

        $container
            ->get(ClassListenerNotifier::class, ClassListenerNotifier::class)
            ->notify($container->get(ClassIteratorInterface::class, ClassIteratorInterface::class));
    }
}
