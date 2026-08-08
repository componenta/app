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
        return $container->get($this->appClass($scope, $container), AppInterface::class);
    }

    /**
     * @return class-string<AppInterface>
     */
    private function appClass(ScopeInterface $scope, ContainerValue $container): string
    {
        $apps = $container->config->get(ConfigKey::APP_BY_SCOPE, []);

        if (!is_array($apps)) {
            throw new LogicException(sprintf(
                'Config key "%s" must contain a map of scope values to App class-strings.',
                ConfigKey::APP_BY_SCOPE,
            ));
        }

        $app = $apps[$scope->value] ?? null;

        if ($app === null) {
            throw new LogicException(sprintf(
                'Unknown scope "%s" - no App is configured.',
                $scope->value,
            ));
        }

        if (!is_string($app) || !is_a($app, AppInterface::class, true)) {
            throw new LogicException(sprintf(
                'App configured for scope "%s" must be a class-string implementing %s, %s given.',
                $scope->value,
                AppInterface::class,
                is_string($app) ? $app : get_debug_type($app),
            ));
        }

        return $app;
    }
}
