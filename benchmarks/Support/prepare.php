<?php

declare(strict_types=1);

use Componenta\App\Benchmarks\Fixture;
use Componenta\App\Build\ApplicationBuildOrchestrator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Fixture.php';

if (PHP_SAPI !== 'cli' || !isset($argv[1])) {
    throw new LogicException('This is a PHPBench fixture preparation helper.');
}

$fixture = new Fixture($argv[1]);
$fixture->registerAutoload();
$fixture->container(false)->get(ApplicationBuildOrchestrator::class)->build();
$fixture->assertArtifacts(true);
