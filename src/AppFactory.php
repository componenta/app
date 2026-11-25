<?php

declare(strict_types=1);

namespace Componenta\App;

use Componenta\Config\ContainerValue;
use Componenta\Scope\ScopeInterface;
use LogicException;

final class AppFactory implements AppFactoryInterface
{
    public function createApp(ScopeInterface $scope, ContainerValue $container): AppInterface
    {
        $applications = $container->config->get(ConfigKey::APPLICATIONS, []);

        if (!is_array($applications)) {
            throw new LogicException(sprintf(
                'Config key "%s" must map scope values to application service IDs.',
                ConfigKey::APPLICATIONS,
            ));
        }

        foreach ($applications as $value => $id) {
            if (trim((string) $value) === '' || !is_string($id) || trim($id) === '') {
                throw new LogicException(sprintf(
                    'Config key "%s" must map non-empty scope values to non-empty application service IDs.',
                    ConfigKey::APPLICATIONS,
                ));
            }
        }

        $id = $applications[$scope->value] ?? throw new LogicException(sprintf(
            'Unknown scope "%s" - no application is registered.',
            $scope->value,
        ));

        return $container->get($id, AppInterface::class);
    }
}
