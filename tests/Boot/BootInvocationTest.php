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
        ])->and($listener->finalized)->toBeTrue()
            ->and($listener->bootInvocations)->toHaveCount(2);
    });

});
