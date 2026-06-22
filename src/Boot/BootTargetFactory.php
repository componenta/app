<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

use Componenta\App\AppInterface;
use Componenta\App\ConfigKey;
use Componenta\Config\ContainerValue;
use Componenta\Scope\ScopeInterface;
use LogicException;

final readonly class BootTargetFactory implements BootTargetFactoryInterface
{
    public function __construct(
        private ContainerValue $container,
    ) {}

    public function create(AppInterface $app, ScopeInterface $scope): object
    {
        foreach ($this->adapters() as $adapterClass) {
            $adapter = $this->container->get($adapterClass, BootTargetAdapterInterface::class);

            if (!$adapter->supports($scope)) {
                continue;
            }

            return $adapter->create($app, $scope);
        }

        throw new LogicException(sprintf(
            'Unknown scope "%s" - no matching boot target adapter.',
            $scope->value,
        ));
    }

    /**
     * @return list<class-string<BootTargetAdapterInterface>>
     */
    private function adapters(): array
    {
        $adapters = $this->container->config->get(ConfigKey::BOOT_TARGET_ADAPTERS, []);

        if (!is_array($adapters)) {
            throw new LogicException(sprintf(
                'Config key "%s" must contain a list of boot target adapter class-strings.',
                ConfigKey::BOOT_TARGET_ADAPTERS,
            ));
        }

        foreach ($adapters as $adapter) {
            if (!is_string($adapter) || !is_a($adapter, BootTargetAdapterInterface::class, true)) {
                throw new LogicException(sprintf(
                    'Boot target adapter entry must be a class-string implementing %s, %s given.',
                    BootTargetAdapterInterface::class,
                    is_string($adapter) ? $adapter : get_debug_type($adapter),
                ));
            }
        }

        return array_values($adapters);
    }
}
