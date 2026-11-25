<?php

declare(strict_types=1);

namespace Componenta\App\Benchmarks;

use Componenta\App\AppFactoryInterface;
use Componenta\App\AppInterface;
use Componenta\App\Boot\BootContext;
use Componenta\App\Boot\BootInvocation;
use Componenta\App\Boot\BootInvocationRunnerInterface;
use Componenta\App\Boot\BootloaderProviderInterface;
use Componenta\App\Boot\BootTargetFactoryInterface;
use Componenta\App\Runner;
use Componenta\App\Scope;
use Componenta\Config\ContainerValue;
use Componenta\DI\Attribute\Config;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Env;
use PhpBench\Attributes as Bench;

require_once dirname(__DIR__) . '/Support/Fixture.php';

#[Bench\BeforeMethods('setUp')]
#[Bench\Revs(1000)]
#[Bench\Iterations(7)]
#[Bench\Warmup(1)]
final class RuntimeBench
{
    private ContainerValue $container;
    private AppFactoryInterface $appFactory;
    private AppInterface $app;
    private BootTargetFactoryInterface $targetFactory;
    private BootloaderProviderInterface $provider;
    private BootContext $context;
    private BootInvocationRunnerInterface $runner;

    public function setUp(): void
    {
        $this->container = Fixture::runtimeContainer();
        $this->appFactory = $this->container->get(AppFactoryInterface::class);
        $this->app = $this->appFactory->createApp(Scope::HTTP, $this->container);
        $this->targetFactory = $this->container->get(BootTargetFactoryInterface::class);
        $this->context = new BootContext(
            $this->container,
            Scope::HTTP,
            $this->targetFactory->create($this->app, Scope::HTTP),
        );
        $this->provider = $this->container->get(BootloaderProviderInterface::class);
        $this->runner = $this->container->get(BootInvocationRunnerInterface::class);
    }

    public function benchResolveApplication(): void
    {
        $this->appFactory->createApp(Scope::HTTP, $this->container);
    }

    public function benchCreateBootTarget(): void
    {
        $this->targetFactory->create($this->app, Scope::HTTP);
    }

    public function benchSelectBootloaders(): void
    {
        foreach ($this->provider->provideFor($this->context) as $loader) {
            // Consume the provider's lazy selection, without invoking loaders.
        }
    }

    public function parameterCases(): iterable
    {
        yield 'literal' => ['kind' => 'literal'];
        yield 'entry-id' => ['kind' => 'entry'];
        yield 'config' => ['kind' => 'config'];
        yield 'environment' => ['kind' => 'env'];
    }

    #[Bench\ParamProviders('parameterCases')]
    public function benchBootInvocation(array $params): void
    {
        $value = match ($params['kind']) {
            'literal' => 42,
            'entry' => new EntryId('bench.entry'),
            'config' => new Config('bench.value'),
            'env' => new Env('BENCH_VALUE'),
        };
        $this->runner->run([new BootInvocation(BenchmarkState::class, 'invoke', params: ['value' => $value])]);
    }

    #[Bench\Revs(100)]
    public function benchRunnerWithoutDiscovery(): void
    {
        Runner::run(Scope::HTTP, $this->container);
    }
}
