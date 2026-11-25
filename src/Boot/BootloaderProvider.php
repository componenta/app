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
        $bootloaders = $context->container->config->get(ConfigKey::BOOTLOADERS, []);
        if (!is_array($bootloaders) || !array_is_list($bootloaders)) {
            throw new LogicException(sprintf(
                'Config key "%s" must contain a list of bootloader class-strings.',
                ConfigKey::BOOTLOADERS,
            ));
        }

        $seen = [];
        foreach ($bootloaders as $bootloader) {
            if (!is_string($bootloader) || !is_a($bootloader, BootloaderInterface::class, true)) {
                throw new LogicException(sprintf(
                    'Bootloader entry must be a class-string implementing %s, %s given.',
                    BootloaderInterface::class,
                    is_string($bootloader) ? $bootloader : get_debug_type($bootloader),
                ));
            }

            $key = strtolower(ltrim($bootloader, '\\'));
            if (isset($seen[$key])) {
                throw new LogicException(sprintf('Duplicate bootloader registration "%s".', $bootloader));
            }
            $seen[$key] = true;
        }

        $instances = [];
        $resolved = [];
        foreach ($bootloaders as $bootloader) {
            $instance = $context->container->get($bootloader, BootloaderInterface::class);
            $identity = spl_object_id($instance);
            if (isset($resolved[$identity])) {
                throw new LogicException(sprintf(
                    'Bootloader registrations "%s" and "%s" resolve to the same instance.',
                    $resolved[$identity],
                    $bootloader,
                ));
            }
            $resolved[$identity] = $bootloader;
            $instances[] = $instance;
        }

        foreach ($instances as $instance) {
            if (!$instance->supports($context)) {
                continue;
            }

            yield $instance;
        }
    }
}
