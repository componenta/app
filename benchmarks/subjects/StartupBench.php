<?php

declare(strict_types=1);

namespace Componenta\App\Benchmarks;

use Componenta\App\Boot\ClassDiscoveryBootloader;
use Componenta\App\Boot\BootContext;
use Componenta\App\Config\ConfigFactoryResult;
use Componenta\App\ConfigKey;
use Componenta\App\Runner;
use Componenta\App\Scope;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\ContainerValue;
use Componenta\DI\ContainerFactory;
use LogicException;
use PhpBench\Attributes as Bench;
use stdClass;

require_once dirname(__DIR__) . '/Support/Fixture.php';

#[Bench\Revs(1)]
#[Bench\Iterations(7)]
#[Bench\ParamProviders('cases')]
#[Bench\AfterMethods('tearDown')]
final class StartupBench
{
    private Fixture $fixture;
    private ContainerValue $container;
    private ConfigFactoryResult $configuration;
    private string $operation = '';
    private mixed $result = null;

    public function cases(): iterable
    {
        foreach ([300, 1000] as $count) {
            foreach ([false, true] as $prepared) {
                yield ($prepared ? 'prepared-' : 'source-') . $count => [
                    'count' => $count,
                    'prepared' => $prepared,
                ];
            }
        }
    }

    public function setUp(array $params): void
    {
        BenchmarkState::$bootCalls = 0;
        BenchmarkState::$autoloads = 0;
        $this->fixture = Fixture::create($params['count']);
        if ($params['prepared']) {
            $this->fixture->prepare();
        }
        $this->fixture->registerAutoload();
    }

    public function setUpConfiguration(array $params): void
    {
        $this->setUp($params);
        $this->configuration = $this->fixture->configuration($params['prepared']);
    }

    public function setUpContainer(array $params): void
    {
        $this->setUp($params);
        $this->container = $this->fixture->container($params['prepared']);
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchColdBootstrap(array $params): void
    {
        $container = $this->fixture->container($params['prepared']);
        $this->result = Runner::run(Scope::HTTP, $container);
        $this->container = $container;
        $this->operation = 'bootstrap';
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchConfiguration(array $params): void
    {
        $this->configuration = $this->fixture->configuration($params['prepared']);
        $this->operation = 'config';
    }

    #[Bench\BeforeMethods('setUpConfiguration')]
    public function benchContainer(array $params): void
    {
        $this->container = (new ContainerFactory())->create($this->configuration->config, $this->configuration->dependencies);
        $this->operation = 'container';
    }

    #[Bench\BeforeMethods('setUpContainer')]
    public function benchDiscovery(array $params): void
    {
        $this->result = $this->container->get(ClassIteratorInterface::class, ClassIteratorInterface::class)->count();
        $this->operation = 'discovery';
    }

    #[Bench\BeforeMethods('setUpContainer')]
    public function benchBootDiscovery(array $params): void
    {
        $this->container->get(ClassDiscoveryBootloader::class)->boot(
            new BootContext($this->container, Scope::HTTP, new stdClass()),
        );
        $this->operation = 'boot';
    }

    public function tearDown(array $params): void
    {
        try {
            if ($this->operation === 'config') {
                if ($this->configuration->discovered === null || BenchmarkState::$autoloads !== 0) {
                    throw new LogicException('Configuration must leave discovery classes unloaded.');
                }
                return;
            }

            if ($this->container->get(ConfigKey::DISCOVERY_PREPARED) !== $params['prepared']) {
                throw new LogicException('Unexpected discovery mode.');
            }
            if ($this->operation === 'bootstrap' && $this->result !== 17) {
                throw new LogicException('Application runtime did not execute.');
            }
            if ($this->operation === 'discovery' && $this->result !== $params['count']) {
                throw new LogicException('Unexpected number of discovered declarations.');
            }
            $executesBoot = in_array($this->operation, ['boot', 'bootstrap'], true);
            $expected = $executesBoot ? intdiv($params['count'] + 19, 20) : 0;
            $autoloads = $executesBoot && !$params['prepared'] ? $params['count'] : $expected;
            if (BenchmarkState::$bootCalls !== $expected || BenchmarkState::$autoloads !== $autoloads) {
                throw new LogicException(sprintf('Unexpected boot behavior: %d calls, %d autoloads.', BenchmarkState::$bootCalls, BenchmarkState::$autoloads));
            }
        } finally {
            $this->fixture->close();
        }
    }
}
