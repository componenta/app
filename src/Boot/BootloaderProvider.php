<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

use Componenta\App\ConfigKey;
use LogicException;
/**
 * Default provider - reads {@see ConfigKey::BOOTLOADERS} from config,
 * resolves each entry, and yields bootloaders accepted by their runtime
 * {@see BootloaderInterface::supports()} check.
 */
final class BootloaderProvider implements BootloaderProviderInterface
{
    public function provideFor(BootContext $context): iterable
    {
        foreach ($context->container->config->get(ConfigKey::BOOTLOADERS, []) as $bootloader) {
            if (!is_string($bootloader) || !is_a($bootloader, BootloaderInterface::class, true)) {
                throw new LogicException(sprintf(
                    'Bootloader entry must be a class-string implementing %s, %s given.',
                    BootloaderInterface::class,
                    is_string($bootloader) ? $bootloader : get_debug_type($bootloader),
                ));
            }

            $instance = $context->container->get($bootloader);

            if (!$instance instanceof BootloaderInterface || !$instance->supports($context)) {
                continue;
            }

            yield $instance;
        }
    }
}
