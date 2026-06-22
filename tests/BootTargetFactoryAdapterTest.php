<?php

declare(strict_types=1);

use Componenta\App\AppInterface;
use Componenta\App\Boot\BootTargetAdapterInterface;
use Componenta\App\Boot\BootTargetFactory;
use Componenta\App\ConfigKey;
use Componenta\App\Scope;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Scope\ScopeInterface;
use Psr\Container\ContainerInterface;

final class BootTargetFactoryAdapterTestContainer implements ContainerInterface
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

final class BootTargetFactoryAdapterTestApp implements AppInterface
{
    public function run(): ?int
    {
        return null;
    }
}

final readonly class BootTargetFactoryAdapterTestTarget {}

final class BootTargetFactoryAdapterTestUnsupportedAdapter implements BootTargetAdapterInterface
{
    public function supports(ScopeInterface $scope): bool
    {
        return false;
    }

    public function create(AppInterface $app, ScopeInterface $scope): object
    {
        throw new RuntimeException('Unsupported adapter should not be called.');
    }
}

final class BootTargetFactoryAdapterTestSupportedAdapter implements BootTargetAdapterInterface
{
    public ?AppInterface $app = null;
    public ?ScopeInterface $scope = null;

    public function __construct(
        private readonly object $target,
    ) {}

    public function supports(ScopeInterface $scope): bool
    {
        return $scope->matches(Scope::HTTP);
    }

    public function create(AppInterface $app, ScopeInterface $scope): object
    {
        $this->app = $app;
        $this->scope = $scope;

        return $this->target;
    }
}

describe('boot target factory adapters', function (): void {
    it('creates a boot target through the first supporting configured adapter', function (): void {
        $app = new BootTargetFactoryAdapterTestApp();
        $target = new BootTargetFactoryAdapterTestTarget();
        $adapter = new BootTargetFactoryAdapterTestSupportedAdapter($target);
        $config = new Config([
            ConfigKey::BOOT_TARGET_ADAPTERS => [
                BootTargetFactoryAdapterTestUnsupportedAdapter::class,
                BootTargetFactoryAdapterTestSupportedAdapter::class,
            ],
        ]);
        $container = new BootTargetFactoryAdapterTestContainer([
            BootTargetFactoryAdapterTestUnsupportedAdapter::class => new BootTargetFactoryAdapterTestUnsupportedAdapter(),
            BootTargetFactoryAdapterTestSupportedAdapter::class => $adapter,
        ]);

        $result = (new BootTargetFactory(new ContainerValue($container, $config)))->create($app, Scope::HTTP);

        expect($result)->toBe($target)
            ->and($adapter->app)->toBe($app)
            ->and($adapter->scope)->toBe(Scope::HTTP);
    });

    it('reports unsupported scopes by scope value', function (): void {
        $app = new BootTargetFactoryAdapterTestApp();
        $container = new BootTargetFactoryAdapterTestContainer([]);
        $config = new Config([
            ConfigKey::BOOT_TARGET_ADAPTERS => [],
        ]);

        expect(fn () => (new BootTargetFactory(new ContainerValue($container, $config)))->create($app, Scope::HTTP))
            ->toThrow(LogicException::class, 'Unknown scope "http" - no matching boot target adapter.');
    });
    it('rejects invalid boot target adapter config values', function (): void {
        $app = new BootTargetFactoryAdapterTestApp();
        $container = new BootTargetFactoryAdapterTestContainer([]);
        $config = new Config([
            ConfigKey::BOOT_TARGET_ADAPTERS => [
                stdClass::class,
            ],
        ]);

        expect(fn () => (new BootTargetFactory(new ContainerValue($container, $config)))->create($app, Scope::HTTP))
            ->toThrow(LogicException::class, 'Boot target adapter entry must be a class-string');
    });
});
