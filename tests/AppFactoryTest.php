<?php

declare(strict_types=1);

use Componenta\App\AppFactory;
use Componenta\App\AppInterface;
use Componenta\App\ConfigKey;
use Componenta\App\Scope;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Psr\Container\ContainerInterface;

final class AppFactoryTestContainer implements ContainerInterface
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

final class AppFactoryTestHttpApp implements AppInterface
{
    public function run(): ?int
    {
        return null;
    }
}

final class AppFactoryTestConsoleApp implements AppInterface
{
    public function run(): ?int
    {
        return 0;
    }
}

describe('app factory', function (): void {
    it('resolves the application configured for the active scope', function (): void {
        $httpApp = new AppFactoryTestHttpApp();
        $consoleApp = new AppFactoryTestConsoleApp();
        $container = new AppFactoryTestContainer([
            AppFactoryTestHttpApp::class => $httpApp,
            AppFactoryTestConsoleApp::class => $consoleApp,
        ]);
        $config = new Config([
            ConfigKey::APP_BY_SCOPE => [
                Scope::HTTP->value => AppFactoryTestHttpApp::class,
                Scope::CLI->value => AppFactoryTestConsoleApp::class,
            ],
        ]);
        $factory = new AppFactory();
        $containerValue = new ContainerValue($container, $config);

        $httpResult = $factory->createApp(Scope::HTTP, $containerValue);
        $consoleResult = $factory->createApp(Scope::CLI, $containerValue);

        expect($httpResult)->toBe($httpApp)
            ->and($consoleResult)->toBe($consoleApp);
    });

    it('rejects an unknown scope', function (): void {
        $container = new AppFactoryTestContainer([]);
        $config = new Config([ConfigKey::APP_BY_SCOPE => []]);

        expect(fn () => (new AppFactory())->createApp(
            Scope::WEBSOCKET,
            new ContainerValue($container, $config),
        ))->toThrow(LogicException::class, 'Unknown scope "websocket" - no App is configured.');
    });

    it('rejects an application that does not implement the public contract', function (): void {
        $container = new AppFactoryTestContainer([]);
        $config = new Config([
            ConfigKey::APP_BY_SCOPE => [
                Scope::HTTP->value => stdClass::class,
            ],
        ]);

        expect(fn () => (new AppFactory())->createApp(
            Scope::HTTP,
            new ContainerValue($container, $config),
        ))->toThrow(LogicException::class, 'must be a class-string implementing');
    });
});
