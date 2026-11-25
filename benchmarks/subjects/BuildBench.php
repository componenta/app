<?php

declare(strict_types=1);

namespace Componenta\App\Benchmarks;

use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\Config\ContainerValue;
use PhpBench\Attributes as Bench;

require_once dirname(__DIR__) . '/Support/Fixture.php';

#[Bench\Revs(1)]
#[Bench\Iterations(7)]
#[Bench\ParamProviders('sizes')]
#[Bench\AfterMethods('tearDown')]
final class BuildBench
{
    private Fixture $fixture;
    private ContainerValue $container;
    private bool $built;

    public function sizes(): iterable
    {
        foreach ([300, 1000] as $count) {
            yield (string) $count => ['count' => $count];
        }
    }

    public function setUpBuild(array $params): void
    {
        $this->fixture = Fixture::create($params['count']);
        $this->fixture->registerAutoload();
        $this->container = $this->fixture->container(false);
    }

    public function setUpClean(array $params): void
    {
        $this->setUpBuild($params);
        $this->fixture->prepare();
    }

    #[Bench\BeforeMethods('setUpBuild')]
    public function benchBuild(array $params): void
    {
        $this->container->get(ApplicationBuildOrchestrator::class)->build();
        $this->built = true;
    }

    #[Bench\BeforeMethods('setUpClean')]
    public function benchClean(array $params): void
    {
        $this->container->get(ApplicationBuildOrchestrator::class)->clean();
        $this->built = false;
    }

    public function tearDown(array $params): void
    {
        try {
            $this->fixture->assertArtifacts($this->built);
        } finally {
            $this->fixture->close();
        }
    }
}
