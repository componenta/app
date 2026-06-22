<?php

declare(strict_types=1);

namespace Componenta\App;

use Componenta\Config\ContainerValue;
use Componenta\Scope\ScopeInterface;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

final class AppFactory implements AppFactoryInterface
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function createApp(ScopeInterface $scope, ContainerValue $container): AppInterface
    {
        foreach ($this->adapters($container) as $adapterClass) {
            $adapter = $container->get($adapterClass, AppAdapterInterface::class);

            if (!$adapter->supports($scope)) {
                continue;
            }

            return $adapter->createApp($scope, $container);
        }

        throw new LogicException(sprintf(
            'Unknown scope "%s" - no matching App adapter.',
            $scope->value,
        ));
    }

    /**
     * @return list<class-string<AppAdapterInterface>>
     */
    private function adapters(ContainerValue $container): array
    {
        $adapters = $container->config->get(ConfigKey::APP_ADAPTERS, []);

        if (!is_array($adapters)) {
            throw new LogicException(sprintf(
                'Config key "%s" must contain a list of app adapter class-strings.',
                ConfigKey::APP_ADAPTERS,
            ));
        }

        foreach ($adapters as $adapter) {
            if (!is_string($adapter) || !is_a($adapter, AppAdapterInterface::class, true)) {
                throw new LogicException(sprintf(
                    'App adapter entry must be a class-string implementing %s, %s given.',
                    AppAdapterInterface::class,
                    is_string($adapter) ? $adapter : get_debug_type($adapter),
                ));
            }
        }

        return array_values($adapters);
    }
}
