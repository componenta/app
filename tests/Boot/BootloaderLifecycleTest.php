<?php

declare(strict_types=1);

use Componenta\App\Boot\BootContext;
use Componenta\App\Boot\Bootloader;
use Componenta\App\Boot\BootloaderProvider;
use Componenta\App\ConfigKey;
use Componenta\App\Scope;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\CallableInvokerInterface;
use Componenta\Scope\Scopes;
use Psr\Container\ContainerInterface;

final class BootLifecycleLoader extends Bootloader
{
    public int $checks = 0;
    public int $calls = 0;

    public Scopes $scopes {
        get => Scopes::of(Scope::HTTP);
    }

    protected function supportsContext(BootContext $context): bool
    {
        return ++$this->checks === 1;
    }

    public function __invoke(): void
    {
        ++$this->calls;
    }
}

final class BootLifecycleContainer implements ContainerInterface
{
    public function __construct(private readonly array $entries) {}
    public function get(string $id): mixed
    {
        return $this->entries[$id] ?? throw new RuntimeException("Missing entry {$id}");
    }
    public function has(string $id): bool { return array_key_exists($id, $this->entries); }
}

function bootLifecycleContext(array $ids, BootLifecycleLoader $loader): BootContext
{
    $invoker = new class implements CallableInvokerInterface {
        public function call(mixed $callable, array $params = []): mixed { return $callable(...$params); }
    };
    return new BootContext(
        new ContainerValue(new BootLifecycleContainer([
            BootLifecycleLoader::class => $loader,
            CallableInvokerInterface::class => $invoker,
        ]), new Config([ConfigKey::BOOTLOADERS => $ids], new Environment([]))),
        Scope::HTTP,
        new stdClass(),
    );
}

it('checks bootloader applicability once and executes the selected loader', function (): void {
    $loader = new BootLifecycleLoader();
    $context = bootLifecycleContext([BootLifecycleLoader::class], $loader);
    foreach ((new BootloaderProvider())->provideFor($context) as $selected) {
        $selected->boot($context);
    }
    expect($loader->checks)->toBe(1)->and($loader->calls)->toBe(1);
});

it('rejects a malformed later bootloader registration before executing any loader', function (string $later): void {
    $loader = new BootLifecycleLoader();
    $context = bootLifecycleContext([BootLifecycleLoader::class, $later], $loader);
    expect(function () use ($context): void {
        foreach ((new BootloaderProvider())->provideFor($context) as $selected) {
            $selected->boot($context);
        }
    })->toThrow(LogicException::class);
    expect($loader->calls)->toBe(0)->and($loader->checks)->toBe(0);
})->with([stdClass::class, BootLifecycleLoader::class]);


final class BootReentryLoader extends Bootloader
{
    public array $scopesSeen = [];
    public bool $reenter = false;
    public ?DomainException $failure = null;

    public Scopes $scopes {
        get => Scopes::of(Scope::HTTP, Scope::CLI);
    }

    public function __invoke(): void
    {
        $this->scopesSeen[] = $this->context->scope;
        if ($this->reenter) {
            $this->reenter = false;
            $this->boot($this->context);
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}

it('rejects recursive boot and releases context after failures so a subsequent supported invocation can run', function (): void {
    $loader = new BootReentryLoader();
    $invoker = new class implements CallableInvokerInterface {
        public function call(mixed $callable, array $params = []): mixed { return $callable(...$params); }
    };
    $container = new ContainerValue(
        new BootLifecycleContainer([CallableInvokerInterface::class => $invoker]),
        new Config([], new Environment([])),
    );
    $first = new BootContext($container, Scope::HTTP, new stdClass());
    $second = new BootContext($container, Scope::CLI, new stdClass());
    expect($loader->supports($first))->toBeTrue()
        ->and($loader->supports($second))->toBeTrue();

    $loader->reenter = true;
    expect(fn () => $loader->boot($first))->toThrow(LogicException::class, 'recursively');
    $failure = new DomainException('Startup failure');
    $loader->failure = $failure;
    expect(fn () => $loader->boot($first))->toThrow(DomainException::class, 'Startup failure');
    $loader->failure = null;

    $loader->boot($second);
    expect($loader->scopesSeen)->toBe([Scope::HTTP, Scope::HTTP, Scope::CLI]);
});


it('rejects aliases resolving to the same bootloader before starting any bootloader', function (): void {
    $loader = new BootReentryLoader();
    $invoker = new class implements CallableInvokerInterface {
        public function call(mixed $callable, array $params = []): mixed { return $callable(...$params); }
    };
    $context = new BootContext(
        new ContainerValue(new BootLifecycleContainer([
            BootLifecycleLoader::class => $loader,
            BootReentryLoader::class => $loader,
            CallableInvokerInterface::class => $invoker,
        ]), new Config([
            ConfigKey::BOOTLOADERS => [BootLifecycleLoader::class, BootReentryLoader::class],
        ], new Environment([]))),
        Scope::HTTP,
        new stdClass(),
    );

    expect(function () use ($context): void {
        foreach ((new BootloaderProvider())->provideFor($context) as $selected) {
            $selected->boot($context);
        }
    })->toThrow(LogicException::class, 'same instance');
    expect($loader->scopesSeen)->toBe([]);
});
