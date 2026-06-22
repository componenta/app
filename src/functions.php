<?php

declare(strict_types=1);

namespace Componenta\App;

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Scope\ScopeInterface;
use Componenta\Stdlib\PathResolverInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;

function run(ScopeInterface $scope, PathResolverInterface $paths): void
{
    if (!chdir($paths->baseDir)) {
        throw new RuntimeException('Unable to change directory to project root.');
    }

    $container = require $paths->resolve('config/container.php');

    if (!$container instanceof ContainerInterface) {
        throw new RuntimeException('config/container.php must return a PSR-11 container.');
    }

    $config = $container->get(Config::class);

    if (!$config instanceof Config) {
        throw new RuntimeException('Container must define Componenta\Config\Config.');
    }

    $code = Runner::run($scope, new ContainerValue($container, $config));

    if ($code !== null) {
        exit($code);
    }
}
