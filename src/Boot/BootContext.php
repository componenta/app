<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

use Componenta\Config\ContainerValue;
use Componenta\Scope\ScopeInterface;
use LogicException;

/**
 * Runtime context handed to bootloaders during the boot phase.
 *
 * Carries the container value, active scope, and boot target while excluding
 * the application's run entry-point - that belongs to {@see \Componenta\App\Runner}
 * and is structurally unreachable through this object.
 */
final readonly class BootContext
{
    public function __construct(
        public ContainerValue $container,
        public ScopeInterface $scope,
        public object $target,
    ) {}

    /**
     * @template T of object
     *
     * @param class-string<T> $contract
     *
     * @return T
     */
    public function target(string $contract): object
    {
        if (!$this->target instanceof $contract) {
            throw new LogicException(sprintf(
                'Boot target must implement %s for scope "%s", %s given.',
                $contract,
                $this->scope->value,
                $this->target::class,
            ));
        }

        return $this->target;
    }
}
