<?php

declare(strict_types=1);

use Componenta\App\AppAdapterInterface;
use Componenta\App\AppFactory;
use Componenta\App\AppInterface;
use Componenta\App\ConfigKey;
use Componenta\App\Scope;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Scope\ScopeInterface;
use Psr\Container\ContainerInterface;

final class AppFactoryAdapterTestContainer implements ContainerInterface
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

final class AppFactoryAdapterTestApp implements AppInterface
{
    public function run(): ?int
    {
        return null;
    }
}

final class AppFactoryAdapterTestUnsupportedAdapter implements AppAdapterInterface
{
    public function supports(ScopeInterface $scope): bool
    {
        return false;
    }

    public function createApp(ScopeInterface $scope, ContainerValue $container): AppInterface
    {
        throw new RuntimeException('Unsupported adapter should not be called.');
    }
}

final class AppFactoryAdapterTestSupportedAdapter implements AppAdapterInterface
{
    public ?ScopeInterface $scope = null;
    public ?ContainerValue $container = null;

    public function __construct(
        private readonly AppInterface $app,
    ) {}

    public function supports(ScopeInterface $scope): bool
    {
        return $scope->matches(Scope::HTTP);
    }

    public function createApp(ScopeInterface $scope, ContainerValue $container): AppInterface
    {
        $this->scope = $scope;
        $this->container = $container;

        return $this->app;
    }
}

describe('app factory adapters', function (): void {
    it('creates an app through the first supporting configured adapter', function (): void {
        $app = new AppFactoryAdapterTestApp();
        $adapter = new AppFactoryAdapterTestSupportedAdapter($app);
        $container = new AppFactoryAdapterTestContainer([
            AppFactoryAdapterTestUnsupportedAdapter::class => new AppFactoryAdapterTestUnsupportedAdapter(),
            AppFactoryAdapterTestSupportedAdapter::class => $adapter,
        ]);
        $config = new Config([
            ConfigKey::APP_ADAPTERS => [
                AppFactoryAdapterTestUnsupportedAdapter::class,
                AppFactoryAdapterTestSupportedAdapter::class,
            ],
        ]);

        $containerValue = new ContainerValue($container, $config);

        $result = (new AppFactory())->createApp(Scope::HTTP, $containerValue);

        expect($result)->toBe($app)
            ->and($adapter->scope)->toBe(Scope::HTTP)
            ->and($adapter->container)->toBe($containerValue);
    });

    it('rejects invalid app adapter config values', function (): void {
        $container = new AppFactoryAdapterTestContainer([]);
        $config = new Config([
            ConfigKey::APP_ADAPTERS => [
                stdClass::class,
            ],
        ]);

        expect(fn () => (new AppFactory())->createApp(Scope::HTTP, new ContainerValue($container, $config)))
            ->toThrow(LogicException::class, 'App adapter entry must be a class-string');
    });
});
