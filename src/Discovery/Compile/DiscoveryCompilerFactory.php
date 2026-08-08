<?php

declare(strict_types=1);

namespace Componenta\App\Discovery\Compile;

use Componenta\ClassFinder\Compile\ConfigKey as CompileConfigKey;
use Componenta\ClassFinder\Compile\ListenerCompilerInterface;
use Componenta\Config\Config;
use RuntimeException;
use Psr\Container\ContainerInterface;

/**
 * Assembles {@see DiscoveryCompiler} from the list of compiler class-strings
 * that every package contributes into {@see ConfigKey::LISTENER_COMPILERS}
 * via its `ConfigProvider::getConfig()`. Each id is resolved from the
 * container when the factory runs, so compilers are only instantiated
 * when `app:build` actually fires.
 */
final class DiscoveryCompilerFactory
{
    public function __invoke(ContainerInterface $container): DiscoveryCompiler
    {
        $config = $container->get(Config::class);

        if (!$config instanceof Config) {
            throw new RuntimeException(sprintf(
                'Container entry "%s" must be a %s instance.',
                Config::class,
                Config::class,
            ));
        }

        $ids = $config->get(CompileConfigKey::LISTENER_COMPILERS, default: []);

        if (!is_array($ids) || !array_is_list($ids)) {
            throw new RuntimeException(sprintf(
                'Config entry "%s" must be a list of compiler service ids.',
                CompileConfigKey::LISTENER_COMPILERS,
            ));
        }

        $compilers = [];

        foreach ($ids as $id) {
            if (!is_string($id) || trim($id) === '') {
                throw new RuntimeException('Listener compiler service ids must be non-empty strings.');
            }

            $compiler = $container->get($id);

            if (!$compiler instanceof ListenerCompilerInterface) {
                throw new RuntimeException(sprintf(
                    'Container entry "%s" must implement %s.',
                    $id,
                    ListenerCompilerInterface::class,
                ));
            }

            $compilers[] = $compiler;
        }

        return new DiscoveryCompiler($compilers);
    }
}
