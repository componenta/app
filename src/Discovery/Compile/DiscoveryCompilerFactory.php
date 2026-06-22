<?php

declare(strict_types=1);

namespace Componenta\App\Discovery\Compile;

use Componenta\ClassFinder\Compile\ConfigKey as CompileConfigKey;
use Componenta\ClassFinder\Compile\ListenerCompilerInterface;
use Componenta\Config\Config;
use Psr\Container\ContainerInterface;

/**
 * Assembles {@see DiscoveryCompiler} from the list of compiler class-strings
 * that every package contributes into {@see ConfigKey::LISTENER_COMPILERS}
 * via its `ConfigProvider::getConfig()`. Each id is resolved from the
 * container when the factory runs, so compilers are only instantiated
 * when `discovery:compile` actually fires.
 */
final class DiscoveryCompilerFactory
{
    public function __invoke(ContainerInterface $container): DiscoveryCompiler
    {
        /** @var Config $config */
        $config = $container->get(Config::class);

        /** @var list<class-string<ListenerCompilerInterface>> $ids */
        $ids = $config->get(CompileConfigKey::LISTENER_COMPILERS, default: []);

        $compilers = array_map(
            static fn (string $id): ListenerCompilerInterface => $container->get($id),
            $ids,
        );

        return new DiscoveryCompiler($compilers);
    }
}
