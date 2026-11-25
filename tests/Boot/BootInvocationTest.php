<?php

declare(strict_types=1);

use Componenta\App\Boot\Boot;
use Componenta\App\Boot\BootInvocationRunner;
use Componenta\App\Boot\BootInvocationRunnerInterface;
use Componenta\App\Boot\BootMethodInvocation;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Config as ConfigAttr;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\CallableExecutorInterface;
use Componenta\Tokenizer\ClassInfo;
use Psr\Container\ContainerInterface;

final class BootInvocationTestContainer implements ContainerInterface
{
    /**
     * @param array<string, mixed> $entries
     */
    public function __construct(private readonly array $entries)
    {
    }

    public function get(string $id): mixed
    {
        return $this->entries[$id] ?? throw new RuntimeException("Missing test entry {$id}.");
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}

final class BootInvocationTestExecutor implements CallableExecutorInterface
{
    public function call(mixed $callable, array $params = []): mixed
    {
        if (!is_callable($callable)) {
            throw new InvalidArgumentException('Boot invocation test callable must be callable.');
        }

        return $callable(...$params);
    }

    public function resolve(mixed $callable): callable
    {
        if (!is_callable($callable)) {
            throw new InvalidArgumentException('Boot invocation test callable must be callable.');
        }

        return $callable;
    }
}

final class BootInvocationFixture
{
    /** @var list<string> */
    public static array $calls = [];

    #[Boot(priority: 1)]
    public static function lower(): void
    {
        self::$calls[] = 'lower';
    }

    #[Boot(
        priority: 10,
        params: [
            'service' => new EntryId('service.name'),
            'config' => new ConfigAttr('feature.name'),
            'env' => new Env('BOOT_ENV'),
        ],
    )]
    public static function higher(string $service, string $config, string $env): void
    {
        self::$calls[] = "higher:{$service}:{$config}:{$env}";
    }
}

beforeEach(function (): void {
    BootInvocationFixture::$calls = [];
});

describe('boot invocations', function (): void {
    it('discovers boot methods and executes them by priority in development discovery', function (): void {
        $container = new BootInvocationTestContainer([
            Config::class => new Config(
                ['feature.name' => 'config-value'],
                new Environment(['BOOT_ENV' => 'env-value']),
            ),
            'service.name' => 'service-value',
        ]);
        $listener = new BootMethodInvocation(new BootInvocationRunner(
            $container,
            new BootInvocationTestExecutor(),
        ));

        $listener->handle(new ClassInfo(BootInvocationFixture::class));
        $listener->finalize();

        expect(BootInvocationFixture::$calls)->toBe([
            'higher:service-value:config-value:env-value',
            'lower',
        ])->and($listener->finalized)->toBeTrue();
    });

});


it('rejects new boot invocations after finalization has started, including on failure', function (bool $fail): void {
    $failure = new DomainException('Boot failed');
    $runner = new class($fail, $failure) implements BootInvocationRunnerInterface {
        public int $calls = 0;
        public function __construct(private bool $fail, private Throwable $failure) {}
        public function run(iterable $invocations): void
        {
            ++$this->calls;
            if ($this->fail) { throw $this->failure; }
        }
    };
    $listener = new BootMethodInvocation($runner);
    $info = new ClassInfo(BootInvocationFixture::class);
    $listener->handle($info);

    if ($fail) {
        expect(fn () => $listener->finalize())->toThrow(DomainException::class, 'Boot failed');
    } else {
        $listener->finalize();
    }

    expect(fn () => $listener->handle($info))
        ->toThrow(\Componenta\ClassFinder\Exception\ListenerAlreadyFinalizedException::class);
    expect(fn () => $listener->finalize())
        ->toThrow(\Componenta\ClassFinder\Exception\ListenerAlreadyFinalizedException::class);
    expect($runner->calls)->toBe(1);
})->with([true, false]);


final class BootTypedParameterFixture
{
    public static array $values = [];

    public static function boot(bool $enabled, int $count, float $rate, string $label, int ...$ids): void
    {
        self::$values = [$enabled, $count, $rate, $label, $ids];
    }
}

it('resolves boot metadata with the same scalar and variadic conversions as DI', function (): void {
    $config = new Config(['label' => 'ready'], new Environment([
        'ENABLED' => 'false', 'COUNT' => '42', 'RATE' => '2.5', 'IDS' => [1, 2],
    ]));
    $container = (new \Componenta\DI\ContainerFactory())->create($config, new \Componenta\Config\DependencyDefinitions([]));
    $runner = new BootInvocationRunner($container, $container->get(CallableExecutorInterface::class));
    $runner->run([new \Componenta\App\Boot\BootInvocation(
        class: BootTypedParameterFixture::class,
        method: 'boot',
        params: [0 => new Env(), 'count' => new Env(), 'rate' => new Env(), 'label' => new ConfigAttr(), 'ids' => new Env()],
    )]);

    expect(BootTypedParameterFixture::$values)->toBe([false, 42, 2.5, 'ready', [1, 2]]);
});


it('does not autoload declarations without boot methods when using prepared metadata', function (): void {
    $class = 'PreparedBootEmptyFixture';
    $loads = 0;
    $autoload = static function (string $requested) use ($class, &$loads): void {
        if ($requested === $class) {
            ++$loads;
            class PreparedBootEmptyFixture {}
        }
    };
    $runner = new class implements BootInvocationRunnerInterface {
        public array $calls = [];
        public function run(iterable $invocations): void
        {
            foreach ($invocations as $invocation) { $this->calls[] = $invocation; }
        }
    };
    spl_autoload_register($autoload);
    try {
        $prepared = new BootMethodInvocation($runner, [$class => []]);
        $prepared->handle(new ClassInfo($class));
        $prepared->finalize();
        expect($loads)->toBe(0)->and($runner->calls)->toBe([]);

        $source = new BootMethodInvocation($runner);
        $source->handle(new ClassInfo($class));
        $source->finalize();
        expect($loads)->toBe(1)->and($runner->calls)->toBe([]);
    } finally {
        spl_autoload_unregister($autoload);
    }
});


final class BootDiscoveryRetentionValue
{
    public static ?WeakReference $reference = null;

    public function __construct()
    {
        self::$reference = WeakReference::create($this);
    }
}

final class BootDiscoveryRetentionFixture
{
    public static int $calls = 0;

    #[Boot(params: ['value' => new BootDiscoveryRetentionValue()])]
    public static function start(BootDiscoveryRetentionValue $value): void
    {
        ++self::$calls;
    }
}

it('runs boot attributes once per discovered declaration and releases temporary parameter objects', function (bool $prepared): void {
    BootDiscoveryRetentionFixture::$calls = 0;
    BootDiscoveryRetentionValue::$reference = null;
    $container = new BootInvocationTestContainer([]);
    $listener = new BootMethodInvocation(
        new BootInvocationRunner($container, new BootInvocationTestExecutor()),
        $prepared ? [BootDiscoveryRetentionFixture::class => ['start']] : null,
    );

    $listener->handle(new ClassInfo(BootDiscoveryRetentionFixture::class));
    $listener->handle(new ClassInfo(strtolower(BootDiscoveryRetentionFixture::class)));
    $reference = BootDiscoveryRetentionValue::$reference;
    expect($reference)->toBeInstanceOf(WeakReference::class)
        ->and($reference->get())->toBeInstanceOf(BootDiscoveryRetentionValue::class);

    $listener->finalize();

    expect(BootDiscoveryRetentionFixture::$calls)->toBe(1)
        ->and($reference->get())->toBeNull();
})->with([true, false]);


it('keeps the validated boot map independent of caller-owned reference slots', function (): void {
    $runner = new class implements BootInvocationRunnerInterface {
        public array $methods = [];
        public function run(iterable $invocations): void
        {
            foreach ($invocations as $invocation) {
                $this->methods[] = $invocation->method;
            }
        }
    };

    $first = 'lower';
    $methods = [&$first, 'higher'];
    $listener = new BootMethodInvocation($runner, [BootInvocationFixture::class => &$methods]);

    $first = 'higher';
    $methods = [];

    $listener->handle(new ClassInfo(BootInvocationFixture::class));
    $listener->finalize();

    expect($runner->methods)->toBe(['lower', 'higher']);
});
