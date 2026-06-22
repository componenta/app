<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

use Componenta\App\AppInterface;
use Componenta\Scope\ScopeInterface;

interface BootTargetAdapterInterface
{
    public function supports(ScopeInterface $scope): bool;

    public function create(AppInterface $app, ScopeInterface $scope): object;
}
