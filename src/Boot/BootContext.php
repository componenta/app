<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

use Componenta\App\AppInterface;
use Componenta\Config\ContainerValue;
use Componenta\Scope\ScopeInterface;
use LogicException;

/**
 * Runtime context for trusted bootloaders. The target exposes configuration
 * capabilities only; a runnable application must never be passed as a target.
 * The application container is not a security sandbox.
 */
final readonly class BootContext
{
    public function __construct(
        public ContainerValue $container,
        public ScopeInterface $scope,
        private object $target,
    ) {
        if ($target instanceof AppInterface) {
            throw new LogicException('A runnable application cannot be used as a boot target.');
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $contract
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
