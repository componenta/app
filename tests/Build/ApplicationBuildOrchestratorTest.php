<?php

declare(strict_types=1);

use Componenta\App\Build\ApplicationBuilderInterface;
use Componenta\App\Build\BuildContext;
use Componenta\App\Build\BuildStore;
use Componenta\App\Build\PreparedBuild;
use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\App\Build\ApplicationBuildOrchestratorFactory;
use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\App\ConfigProvider as AppConfigProvider;
use Componenta\Config\Config;
use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigKey;
use Componenta\Config\ConfigProvider;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Exception\NotFoundException;

final class ApplicationBuildTestStorage
{
    public static array $roots = [];
}

function applicationBuildTestStore(): BuildStore
{
    $root = sys_get_temp_dir() . '/app_orchestrator_' . bin2hex(random_bytes(8));
    ApplicationBuildTestStorage::$roots[] = $root;
    return new BuildStore($root);
}

afterEach(function (): void {
    foreach (ApplicationBuildTestStorage::$roots as $root) {
        if (!is_dir($root)) { continue; }
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($root);
    }
    ApplicationBuildTestStorage::$roots = [];
});

final class ApplicationBuildTestSource
{
    public int $loads = 0;
    /** @var list<string> */
    public array $constructed = [];
    /** @var list<string> */
    public array $built = [];
    /** @var list<Config> */
    public array $configs = [];
    public string $value = 'source';
}

final class ApplicationBuildFirstTestBuilder implements ApplicationBuilderInterface
{
    public function __construct(
        private ApplicationBuildTestSource $source,
        private Config $config,
    ) {
        $source->constructed[] = 'first';
    }

    public function build(BuildContext $build): void
    {
        $this->source->built[] = 'first:' . $this->source->value;
        $this->source->configs[] = $this->config;
    }
}

final class ApplicationBuildSecondTestBuilder implements ApplicationBuilderInterface
{
    public function __construct(
        private ApplicationBuildTestSource $source,
        private Config $config,
    ) {
        $source->constructed[] = 'second';
    }

    public function build(BuildContext $build): void
    {
        $this->source->built[] = 'second:' . $this->source->value;
        $this->source->configs[] = $this->config;
    }
}

/** @param callable(): array<array-key, mixed> ...$providers */
function applicationBuildTestContainer(callable ...$providers): ContainerValue
{
    $composition = (new ConfigFactory())->create(
        new Environment(['APP_ENV' => 'production']),
        new AppConfigProvider(),
        static fn (): array => [ConfigKey::DEPENDENCIES => [ConfigKey::SERVICES => [BuildStore::class => applicationBuildTestStore()]]],
        ...$providers,
    );

    return (new ContainerFactory())->create($composition->config, $composition->dependencies);
}

it('composes builders from providers and shares source dependencies and the original Config', function (): void {
    $source = new ApplicationBuildTestSource();
    $first = new class($source) extends ConfigProvider {
        public function __construct(private ApplicationBuildTestSource $source) {}

        protected function getConfig(): array
        {
            return [
                AppConfigKey::BUILDERS => [ApplicationBuildFirstTestBuilder::class],
                'cached.value' => 'stale',
            ];
        }

        protected function getFactories(): array
        {
            return [
                ApplicationBuildTestSource::class => function (): ApplicationBuildTestSource {
                    $this->source->loads++;

                    return $this->source;
                },
                ApplicationBuildFirstTestBuilder::class => static fn (ContainerValue $container) =>
                    new ApplicationBuildFirstTestBuilder(
                        $container->get(ApplicationBuildTestSource::class),
                        $container->config,
                    ),
            ];
        }
    };
    $second = new class extends ConfigProvider {
        protected function getConfig(): array
        {
            return [AppConfigKey::BUILDERS => [ApplicationBuildSecondTestBuilder::class]];
        }
    };
    $container = applicationBuildTestContainer($first, $second);

    expect($source->constructed)->toBe([])
        ->and($source->loads)->toBe(0);

    $orchestrator = $container->get(ApplicationBuildOrchestrator::class);

    expect($orchestrator)->toBeInstanceOf(ApplicationBuildOrchestrator::class)
        ->and($source->constructed)->toBe(['first', 'second'])
        ->and($source->loads)->toBe(1)
        ->and($source->built)->toBe([]);

    $orchestrator->build();

    expect($source->built)->toBe(['first:source', 'second:source'])
        ->and($source->configs)->toBe([$container->config, $container->config])
        ->and($container->get(Config::class))->toBe($container->config)
        ->and($container->config->has(ConfigKey::DEPENDENCIES))->toBeFalse()
        ->and($container->config->environment->get('APP_ENV'))->toBe('production');
});

it('rejects every invalid registration before resolving any builder', function (mixed $ids): void {
    $source = new ApplicationBuildTestSource();
    $container = applicationBuildTestContainer(static fn (): array => [
        AppConfigKey::BUILDERS => $ids,
        ConfigKey::DEPENDENCIES => [
            ConfigKey::SERVICES => [ApplicationBuildTestSource::class => $source],
        ],
    ]);

    expect(fn () => (new ApplicationBuildOrchestratorFactory())($container))
        ->toThrow(RuntimeException::class, AppConfigKey::BUILDERS);
    expect($source->constructed)->toBe([])
        ->and($source->built)->toBe([]);
})->with([
    'null' => [null],
    'scalar' => ['builder.id'],
    'object' => [new stdClass()],
    'map' => [['named' => ApplicationBuildFirstTestBuilder::class]],
    'sparse list' => [[1 => ApplicationBuildFirstTestBuilder::class]],
    'empty ID after valid ID' => [[ApplicationBuildFirstTestBuilder::class, '']],
    'blank ID' => [[ApplicationBuildFirstTestBuilder::class, " \t"]],
    'non-string ID' => [[ApplicationBuildFirstTestBuilder::class, 42]],
    'nested list' => [[ApplicationBuildFirstTestBuilder::class, ['builder.id']]],
    'duplicate ID' => [[ApplicationBuildFirstTestBuilder::class, ApplicationBuildFirstTestBuilder::class]],
]);

it('reports duplicate registrations contributed by different providers', function (): void {
    $provider = static fn (): array => [AppConfigKey::BUILDERS => ['builder.same']];
    $container = applicationBuildTestContainer($provider, $provider);

    expect(fn () => (new ApplicationBuildOrchestratorFactory())($container))
        ->toThrow(RuntimeException::class, 'duplicate builder service ID "builder.same"');
});

it('does not start any builder if a later service is unavailable', function (): void {
    $source = new ApplicationBuildTestSource();
    $container = applicationBuildTestContainer(static fn (): array => [
        AppConfigKey::BUILDERS => [ApplicationBuildFirstTestBuilder::class, 'missing.builder'],
        ConfigKey::DEPENDENCIES => [
            ConfigKey::SERVICES => [ApplicationBuildTestSource::class => $source],
        ],
    ]);

    expect(fn () => (new ApplicationBuildOrchestratorFactory())($container))
        ->toThrow(NotFoundException::class, 'missing.builder');
    expect($source->constructed)->toBe(['first'])
        ->and($source->built)->toBe([]);
});

it('does not start any builder if a later service has the wrong type', function (): void {
    $source = new ApplicationBuildTestSource();
    $container = applicationBuildTestContainer(static fn (): array => [
        AppConfigKey::BUILDERS => [ApplicationBuildFirstTestBuilder::class, 'wrong.builder'],
        ConfigKey::DEPENDENCIES => [
            ConfigKey::SERVICES => [
                ApplicationBuildTestSource::class => $source,
                'wrong.builder' => new stdClass(),
            ],
        ],
    ]);

    expect(fn () => (new ApplicationBuildOrchestratorFactory())($container))
        ->toThrow(RuntimeException::class, 'service "wrong.builder" must implement');
    expect($source->constructed)->toBe(['first'])
        ->and($source->built)->toBe([]);
});

it('accepts an absent or empty builder list', function (array $configuration): void {
    $container = applicationBuildTestContainer(static fn (): array => $configuration);
    $orchestrator = $container->get(ApplicationBuildOrchestrator::class);

    expect($orchestrator->build())->toBeInstanceOf(PreparedBuild::class);
    expect($orchestrator->clean())->toBeNull();
})->with([
    'absent' => [[]],
    'empty' => [[AppConfigKey::BUILDERS => []]],
]);

it('preserves completed effects and propagates the original failure without running later builders', function (): void {
    $effects = new ArrayObject();
    $failure = new DomainException('Builder failed');
    $builder = static fn (Closure $action): ApplicationBuilderInterface =>
        new class($action) implements ApplicationBuilderInterface {
            public function __construct(private Closure $action) {}
            public function build(BuildContext $build): void { ($this->action)(); }
        };
    $orchestrator = new ApplicationBuildOrchestrator([
        $builder(static function () use ($effects): void { $effects[] = 'completed'; }),
        $builder(static function () use ($failure): never { throw $failure; }),
        $builder(static function () use ($effects): void { $effects[] = 'unreachable'; }),
    ], applicationBuildTestStore());

    try {
        $orchestrator->build();
        test()->fail('The build failure must propagate.');
    } catch (DomainException $caught) {
        expect($caught)->toBe($failure);
    }

    expect($effects->getArrayCopy())->toBe(['completed']);
});

it('rejects an invalid builder from a direct constructor before executing any builder', function (): void {
    expect(fn () => new ApplicationBuildOrchestrator((static function (): Generator {
        yield new stdClass();
    })(), applicationBuildTestStore()))->toThrow(InvalidArgumentException::class);
});


it('rejects multiple builder service IDs resolving to the same instance before any build or cleanup', function (): void {
    $builder = new class implements ApplicationBuilderInterface {
        public int $operations = 0;
        public function build(BuildContext $build): void { ++$this->operations; }
        public function clean(): void { ++$this->operations; }
    };
    $container = applicationBuildTestContainer(static fn (): array => [
        AppConfigKey::BUILDERS => ['first.builder', 'second.builder'],
        ConfigKey::DEPENDENCIES => [
            ConfigKey::SERVICES => ['first.builder' => $builder, 'second.builder' => $builder],
        ],
    ]);

    expect(fn () => (new ApplicationBuildOrchestratorFactory())($container))
        ->toThrow(InvalidArgumentException::class, 'same application builder instance');
    expect(fn () => new ApplicationBuildOrchestrator([$builder, $builder], applicationBuildTestStore()))
        ->toThrow(InvalidArgumentException::class, 'same application builder instance');
    expect($builder->operations)->toBe(0);
});

it('allows distinct instances of the same builder class to represent independent build targets', function (): void {
    $make = static fn () => new class implements ApplicationBuilderInterface {
        public int $calls = 0;
        public function build(BuildContext $build): void { ++$this->calls; }
    };
    $first = $make();
    $second = $make();
    (new ApplicationBuildOrchestrator([$first, $second], applicationBuildTestStore()))->build();
    expect($first->calls)->toBe(1)->and($second->calls)->toBe(1);
});
