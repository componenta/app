<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

use Componenta\App\AppInterface;
use Componenta\Scope\ScopeInterface;

interface BootTargetAdapterInterface
{
    public function supports(ScopeInterface $scope): bool;

    /** Return a separate capability object that never exposes the runnable application. */
    public function create(AppInterface $app, ScopeInterface $scope): object;
}
