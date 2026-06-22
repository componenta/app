<?php

declare(strict_types=1);

use Componenta\App\Boot\Boot;
use Componenta\App\Boot\BootContext;
use Componenta\App\Boot\BootInvocation;
use Componenta\App\Boot\BootInvocationRunner;
use Componenta\App\Boot\BootInvocationRunnerInterface;
use Componenta\App\Boot\BootMethodInvocation;
use Componenta\App\Boot\Compile\BootInvocationCompiler;
use Componenta\App\Boot\Compile\BootInvocationSerializer;
use Componenta\App\Boot\CompiledBootInvocationBootloader;
use Componenta\App\ConfigKey;
use Componenta\App\Scope;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
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
    public function __construct(private readonly array $entries) {}

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
        return $callable(...$params);
    }

    public function resolve(mixed $callable): callable
    {
        return $callable;
    }
}

final class BootInvocationNoopRunner implements BootInvocationRunnerInterface
{
    public function run(iterable $invocations): void
    {
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
        ])->and($listener->finalized)->toBeTrue()
            ->and($listener->bootInvocations)->toHaveCount(2);
    });

    it('compiles discovered boot invocations into config metadata', function (): void {
        $listener = new BootMethodInvocation(new BootInvocationNoopRunner());

        $listener->handle(new ClassInfo(BootInvocationFixture::class));
        $listener->finalize();

        $result = (new BootInvocationCompiler())->compile($listener, __DIR__);
        $withParams = array_values(array_filter(
            $result->configValue,
            static fn (array $invocation): bool => $invocation['method'] === 'higher',
        ))[0];

        expect($result->configKey)->toBe(ConfigKey::BOOT_INVOCATIONS)
            ->and($result->configValue)->toHaveCount(2)
            ->and($withParams['class'])->toBe(BootInvocationFixture::class)
            ->and($withParams['params']['service']['type'])->toBe('entry');
    });

    it('runs compiled boot invocations only in production', function (): void {
        $container = new BootInvocationTestContainer([
            Config::class => new Config(
                ['feature.name' => 'config-value'],
                new Environment(['APP_ENV' => 'production', 'BOOT_ENV' => 'env-value']),
            ),
            'service.name' => 'service-value',
        ]);
        $runner = new BootInvocationRunner($container, new BootInvocationTestExecutor());
        $bootloader = new CompiledBootInvocationBootloader($runner);
        $payload = array_map(
            BootInvocationSerializer::serialize(...),
            [
                new BootInvocation(
                    class: BootInvocationFixture::class,
                    method: 'higher',
                    priority: 10,
                    params: [
                        'service' => new EntryId('service.name'),
                        'config' => new ConfigAttr('feature.name'),
                        'env' => new Env('BOOT_ENV'),
                    ],
                ),
            ],
        );
        $config = new Config([
            ConfigKey::BOOT_INVOCATIONS => $payload,
        ], new Environment(['APP_ENV' => 'production']));

        $context = new BootContext(new ContainerValue($container, $config), Scope::HTTP, new stdClass());

        expect($bootloader->supports($context))->toBeTrue();

        $bootloader->boot($context);

        expect(BootInvocationFixture::$calls)->toBe([
            'higher:service-value:config-value:env-value',
        ]);

        $devContext = new BootContext(
            new ContainerValue(
                $container,
                new Config([ConfigKey::BOOT_INVOCATIONS => $payload], new Environment(['APP_ENV' => 'development'])),
            ),
            Scope::HTTP,
            new stdClass(),
        );

        expect($bootloader->supports($devContext))->toBeFalse();
    });
});
