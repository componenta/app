<?php

declare(strict_types=1);

use Componenta\App\AppFactoryInterface;
use Componenta\App\AppInterface;
use Componenta\App\Boot\BootContext;
use Componenta\App\Boot\BootloaderInterface;
use Componenta\App\Boot\BootloaderProviderInterface;
use Componenta\App\Boot\BootTargetFactoryInterface;
use Componenta\App\Boot\ScopedBootloaderSupport;
use Componenta\App\Runner;
use Componenta\App\Scope;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Scope\ScopeInterface;
use Componenta\Scope\Scopes;
use Psr\Container\ContainerInterface;

final class RunnerBootTargetContainer implements ContainerInterface
{
    /**
     * @param array<string, mixed> $entries
     */
    public function __construct(
        private readonly array $entries,
    ) {}

    public function get(string $id): mixed
    {
        return $this->entries[$id] ?? throw new RuntimeException("Missing test entry {$id}.");
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}

final class RunnerBootTargetApp implements AppInterface
{
    public bool $ran = false;

    public function run(): ?int
    {
        $this->ran = true;

        return 17;
    }
}

final readonly class RunnerBootTargetAppFactory implements AppFactoryInterface
{
    public function __construct(
        private AppInterface $app,
    ) {}

    public function createApp(ScopeInterface $scope, ContainerValue $container): AppInterface
    {
        return $this->app;
    }
}

final class RunnerBootTargetFactory implements BootTargetFactoryInterface
{
    public ?AppInterface $app = null;
    public ?ScopeInterface $scope = null;

    public function __construct(
        private readonly object $target,
    ) {}

    public function create(AppInterface $app, ScopeInterface $scope): object
    {
        $this->app = $app;
        $this->scope = $scope;

        return $this->target;
    }
}

final class RunnerBootTargetProvider implements BootloaderProviderInterface
{
    public ?BootContext $context = null;

    public function __construct(
        private readonly BootloaderInterface $bootloader,
    ) {}

    public function provideFor(BootContext $context): iterable
    {
        $this->context = $context;

        yield $this->bootloader;
    }
}

final class RunnerBootTargetBootloader implements BootloaderInterface
{
    use ScopedBootloaderSupport;

    public ?BootContext $context = null;

    public Scopes $scopes {
        get => Scopes::of(Scope::HTTP);
    }

    public function boot(BootContext $context): void
    {
        $this->context = $context;
    }
}

final readonly class RunnerBootTargetMarker {}

describe('boot target factory', function () {
    it('lets runner boot through an explicit target without mutating the PSR container', function () {
        $app = new RunnerBootTargetApp();
        $target = new RunnerBootTargetMarker();
        $targetFactory = new RunnerBootTargetFactory($target);
        $bootloader = new RunnerBootTargetBootloader();
        $provider = new RunnerBootTargetProvider($bootloader);
        $container = new RunnerBootTargetContainer([
            AppFactoryInterface::class => new RunnerBootTargetAppFactory($app),
            BootTargetFactoryInterface::class => $targetFactory,
            BootloaderProviderInterface::class => $provider,
        ]);
        $config = new Config([]);

        $exitCode = Runner::run(Scope::HTTP, new ContainerValue($container, $config));

        expect($exitCode)->toBe(17)
            ->and($targetFactory->app)->toBe($app)
            ->and($targetFactory->scope)->toBe(Scope::HTTP)
            ->and($provider->context)->toBeInstanceOf(BootContext::class)
            ->and($provider->context?->target)->toBe($target)
            ->and($provider->context?->target(RunnerBootTargetMarker::class))->toBe($target)
            ->and($bootloader->context)->toBe($provider->context)
            ->and($app->ran)->toBeTrue();
    });
});
