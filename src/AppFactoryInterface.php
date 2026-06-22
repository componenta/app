<?php

declare(strict_types=1);

namespace Componenta\App;

use Componenta\Config\ContainerValue;
use Componenta\Scope\ScopeInterface;

interface AppFactoryInterface
{
    public function createApp(ScopeInterface $scope, ContainerValue $container): AppInterface;
}
