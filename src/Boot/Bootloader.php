<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\DI\CallableInvokerInterface;
use LogicException;

/**
 * Abstract base bootloader with dependency injection support.
 *
 * Stores the incoming {@see BootContext} and exposes the common services
 * (config, container) to subclasses. Subclasses implement `__invoke()`
 * which is resolved through {@see CallableInvokerInterface} with automatic
 * parameter injection.
 */
abstract class Bootloader implements BootloaderInterface
{
    use ScopedBootloaderSupport;

    protected BootContext $context;
    protected CallableInvokerInterface $invoker;

    protected Config $config {
        get => $this->context->container->config;
    }

    protected ContainerValue $container {
        get => $this->context->container;
    }

    public function boot(BootContext $context): void
    {
        $this->context = $context;

        if (!$this->supports($context)) {
            return;
        }

        if (!method_exists($this, '__invoke')) {
            throw new LogicException(sprintf(
                'Bootloader %s must implement __invoke() method',
                static::class,
            ));
        }

        $this->invoker = $this->container->get(CallableInvokerInterface::class);
        $this->invoker->call([$this, '__invoke']);
    }
}
