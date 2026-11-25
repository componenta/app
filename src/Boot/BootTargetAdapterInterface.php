<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

use Componenta\App\AppInterface;
use Componenta\Scope\ScopeInterface;

interface BootTargetAdapterInterface
{
    public function supports(ScopeInterface $scope): bool;

    /**
     * Return a separate capability object. It must not expose the application's
     * run() method, return the application, or forward arbitrary calls to it.
     */
    public function create(AppInterface $app, ScopeInterface $scope): object;
}
