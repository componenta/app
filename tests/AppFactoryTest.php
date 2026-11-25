<?php

declare(strict_types=1);

use Componenta\App\AppFactory;
use Componenta\App\AppInterface;
use Componenta\App\ConfigKey;
use Componenta\App\Scope;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\Scope\ScopeInterface;
use Psr\Container\ContainerInterface;

final class AppFactoryTestContainer implements ContainerInterface
{
    public array $resolved = [];

    public function __construct(private readonly array $entries = []) {}

    public function get(string $id): mixed
    {
        $this->resolved[] = $id;
        return $this->entries[$id] ?? throw new RuntimeException("Missing test entry {$id}.");
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}

it('resolves only the application registered for the requested scope', function (): void {
    $app = new class implements AppInterface {
        public function run(): ?int { return null; }
    };
    $container = new AppFactoryTestContainer(['http.app' => $app]);
    $config = new Config([ConfigKey::APPLICATIONS => [
        'cli' => 'unavailable.cli',
        'http' => 'http.app',
    ]], new Environment([]));

    expect((new AppFactory())->createApp(Scope::HTTP, new ContainerValue($container, $config)))->toBe($app)
        ->and($container->resolved)->toBe(['http.app']);
});

it('accepts a custom scope with an explicitly registered value', function (): void {
    $scope = new class implements ScopeInterface {
        public string $value { get => 'custom'; }
        public function matches(ScopeInterface $scope): bool { return $scope->value === $this->value; }
    };
    $app = new class implements AppInterface {
        public function run(): ?int { return 7; }
    };
    $container = new AppFactoryTestContainer(['custom.app' => $app]);
    $config = new Config([ConfigKey::APPLICATIONS => ['custom' => 'custom.app']], new Environment([]));

    expect((new AppFactory())->createApp($scope, new ContainerValue($container, $config)))->toBe($app);
});

it('rejects malformed application registrations before resolving a service', function (mixed $registrations): void {
    $container = new AppFactoryTestContainer();
    $config = new Config([ConfigKey::APPLICATIONS => $registrations], new Environment([]));

    expect(fn () => (new AppFactory())->createApp(Scope::HTTP, new ContainerValue($container, $config)))
        ->toThrow(LogicException::class, ConfigKey::APPLICATIONS);
    expect($container->resolved)->toBe([]);
})->with([
    'scalar' => ['http.app'],
    'empty scope' => [['' => 'http.app']],
    'empty ID' => [['http' => '']],
    'non-string ID' => [['http' => 7]],
    'invalid later entry' => [['http' => 'http.app', 'cli' => null]],
]);

it('rejects an unregistered scope without resolving unrelated services', function (): void {
    $container = new AppFactoryTestContainer();
    $config = new Config([ConfigKey::APPLICATIONS => ['cli' => 'cli.app']], new Environment([]));
    expect(fn () => (new AppFactory())->createApp(Scope::HTTP, new ContainerValue($container, $config)))
        ->toThrow(LogicException::class, 'Unknown scope "http"');
    expect($container->resolved)->toBe([]);
});

it('rejects a resolved service that does not implement the application contract', function (): void {
    $container = new AppFactoryTestContainer(['http.app' => new stdClass()]);
    $config = new Config([ConfigKey::APPLICATIONS => ['http' => 'http.app']], new Environment([]));
    expect(fn () => (new AppFactory())->createApp(Scope::HTTP, new ContainerValue($container, $config)))
        ->toThrow(\Componenta\Config\Exception\InvalidContainerValueException::class);
});
