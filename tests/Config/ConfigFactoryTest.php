<?php

declare(strict_types=1);

use Componenta\App\Config\ComposerPackageConfigProvider;
use Componenta\App\Config\ConfigDefinition;
use Componenta\App\Config\ConfigFactory;
use Componenta\App\Config\DiscoveryDefinition;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\ConfigKey;
use Componenta\Config\Environment;
use Componenta\Stdlib\PathResolver;
use Componenta\Stdlib\PathResolverInterface;
use Componenta\Tokenizer\ClassInfo;

final class AppConfigFactoryArtifactProvider
{
    public static int $constructions = 0;
    public static int $calls = 0;

    public function __construct()
    {
        self::$constructions++;
    }

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        self::$calls++;

        return [
            'provider_order' => ['package'],
            ConfigKey::DEPENDENCIES => [
                ConfigKey::SERVICES => [
                    'runtime.closure' => static fn (): string => 'alive',
                ],
            ],
        ];
    }
}

function appConfigFactoryRoot(string $suffix): string
{
    $root = str_replace('\\', '/', sys_get_temp_dir())
        . '/componenta_app_config_factory_'
        . $suffix
        . '_'
        . bin2hex(random_bytes(4));

    if (!mkdir($root . '/src', 0o755, recursive: true) && !is_dir($root . '/src')) {
        throw new RuntimeException('Failed to create App ConfigFactory test root.');
    }

    return $root;
}

function removeAppConfigFactoryRoot(string $root): void
{
    if (!is_dir($root)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo) {
            throw new RuntimeException('Unexpected App ConfigFactory test filesystem entry.');
        }

        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($root);
}

/**
 * @param list<(callable(): mixed)|ComposerPackageConfigProvider> $providers
 */
function appConfigDefinition(array $providers): ConfigDefinition
{
    return new ConfigDefinition(
        providers: $providers,
        discovery: new DiscoveryDefinition(directories: ['src']),
    );
}

it('creates the same Config composition in development and production and invokes every provider occurrence once', function (): void {
    $root = appConfigFactoryRoot('differential');
    file_put_contents($root . '/src/Example.php', "<?php\n\ndeclare(strict_types=1);\n\nfinal class ConfigFactoryExample {}\n");
    $paths = new PathResolver($root);
    $service = new stdClass();
    $calls = ['development' => 0, 'production' => 0];

    try {
        foreach (['development', 'production'] as $mode) {
            $provider = static function () use (&$calls, $mode, $service): iterable {
                $calls[$mode]++;

                yield [
                    'application' => ['mode-independent'],
                    ConfigKey::DEPENDENCIES => [
                        ConfigKey::SERVICES => ['runtime.object' => $service],
                    ],
                ];
                yield ['application' => ['second']];
            };

            $environment = new Environment(['APP_ENV' => $mode]);
            $result = ConfigFactory::create(
                paths: $paths,
                definition: appConfigDefinition([$provider]),
                environment: $environment,
            );
            $services = $result->dependencies->sections[ConfigKey::SERVICES] ?? null;
            if (!is_array($services)) {
                throw new RuntimeException('ConfigFactory must expose a services dependency section.');
            }

            expect($result->composition->config)->toBe($result->config)
                ->and($result->composition->dependencies)->toBe($result->dependencies)
                ->and($result->config->environment)->toBe($environment)
                ->and($result->config->has(ConfigKey::DEPENDENCIES))->toBeFalse()
                ->and($result->config->get('application'))->toBe(['mode-independent', 'second'])
                ->and($services['runtime.object'])->toBe($service)
                ->and($services[PathResolverInterface::class])->toBe($paths)
                ->and($services[ClassIteratorInterface::class])->toBe($result->discovered);
        }

        expect($calls)->toBe(['development' => 1, 'production' => 1]);
    } finally {
        removeAppConfigFactoryRoot($root);
    }
});

it('shares the source iterator with ordinary discovery through DI', function (): void {
    $root = appConfigFactoryRoot('source');
    try {
        file_put_contents($root . '/src/One.php', '<?php class SharedSourceOne {}');
        $result = ConfigFactory::create(new PathResolver($root), appConfigDefinition([]), new Environment([]));
        $container = (new \Componenta\DI\ContainerFactory())->create($result->config, $result->dependencies);
        $source = $container->get('app.discovery.source');
        expect($source)->toBe($result->discovered);
        expect(array_map(static fn (ClassInfo $info): string => $info->fullyQualifiedName, $source->toArray()))
            ->toBe(['SharedSourceOne']);
    } finally {
        removeAppConfigFactoryRoot($root);
    }
});

it('uses current discovery and Composer providers even when an old generation remains', function (): void {
    $root = appConfigFactoryRoot('legacy_generation');
    $fixture = dirname(__DIR__) . '/Fixtures/legacy-discovery';
    try {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen($fixture) + 1);
            $target = $root . '/var/cache/build/' . $relative;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0o755, true);
            }
            copy($file->getPathname(), $target);
        }
        file_put_contents($root . '/src/Current.php', '<?php final class CurrentDiscoveryClass {}');
        $providersFile = $root . '/providers.php';
        file_put_contents($providersFile, '<?php return [' . var_export(AppConfigFactoryArtifactProvider::class, true) . '];');
        $definition = appConfigDefinition([new ComposerPackageConfigProvider($providersFile)]);
        foreach (['development', 'production'] as $mode) {
            $result = ConfigFactory::create(new PathResolver($root), $definition, new Environment(['APP_ENV' => $mode]));
            expect(array_map(static fn (ClassInfo $info): string => $info->fullyQualifiedName, $result->discovered->toArray()))
                ->toBe(['CurrentDiscoveryClass'])
                ->and($result->config->get('provider_order'))->toBe(['package']);
        }
    } finally {
        removeAppConfigFactoryRoot($root);
    }
});

it('materializes every current package provider occurrence in registration order', function (): void {
    $root = appConfigFactoryRoot('package_providers');
    try {
        $file = $root . '/providers.php';
        file_put_contents($file, '<?php return [' . var_export(AppConfigFactoryArtifactProvider::class, true)
            . ', ' . var_export(AppConfigFactoryArtifactProvider::class, true) . '];');
        AppConfigFactoryArtifactProvider::$calls = 0;
        $result = ConfigFactory::create(
            new PathResolver($root),
            appConfigDefinition([
                new ComposerPackageConfigProvider($file),
                static fn (): array => ['provider_order' => ['application']],
            ]),
            new Environment(['APP_ENV' => 'production']),
        );

        expect(AppConfigFactoryArtifactProvider::$calls)->toBe(2)
            ->and($result->config->get('provider_order'))->toBe(['package', 'package', 'application']);
        $runtimeClosure = $result->dependencies->sections[ConfigKey::SERVICES]['runtime.closure'];
        expect($runtimeClosure())->toBe('alive');
    } finally {
        removeAppConfigFactoryRoot($root);
    }
});

it('shares current source with discovery-aware providers in both environments', function (): void {
    $root = appConfigFactoryRoot('discovery_provider');
    try {
        file_put_contents($root . '/src/Current.php', '<?php final class ProviderSourceClass {}');
        $provider = new class implements \Componenta\App\Config\DiscoveryAwareConfigProviderInterface {
            public ?ClassIteratorInterface $discovered = null;
            public function withDiscovered(?ClassIteratorInterface $discovered): static {
                $copy = clone $this;
                $copy->discovered = $discovered;
                return $copy;
            }
            public function __invoke(): array {
                return ['provider.source' => $this->discovered];
            }
        };
        foreach (['development', 'production'] as $mode) {
            $result = ConfigFactory::create(new PathResolver($root), appConfigDefinition([$provider]), new Environment(['APP_ENV' => $mode]));
            $container = (new \Componenta\DI\ContainerFactory())->create($result->config, $result->dependencies);
            expect($result->config->get('provider.source'))->toBe($result->discovered)
                ->and($container->get(\Componenta\App\ConfigKey::DISCOVERY_SOURCE))->toBe($result->discovered)
                ->and($container->get(ClassIteratorInterface::class))->toBe($result->discovered)
                ->and($result->discovered->toArray()[0]->fullyQualifiedName)->toBe('ProviderSourceClass');
        }
    } finally {
        removeAppConfigFactoryRoot($root);
    }
});

it('uses built discovery at runtime and gives builders a fresh shared source for a rebuild', function (): void {
    $root = appConfigFactoryRoot('built_discovery');
    $file = $root . '/src/Original.php';
    $autoload = static function (string $class) use ($root): void {
        $file = match ($class) {
            'BuiltDiscoveryOriginal' => $root . '/src/Original.php',
            'BuiltDiscoveryAdded' => $root . '/src/Added.php',
            default => null,
        };
        if ($file !== null) { require_once $file; }
    };
    spl_autoload_register($autoload);
    try {
        file_put_contents($file, '<?php final class BuiltDiscoveryOriginal {}');
        $paths = new PathResolver($root);
        $definition = appConfigDefinition([new \Componenta\App\ConfigProvider()]);
        $initial = ConfigFactory::create($paths, $definition, new Environment([]));
        $container = (new \Componenta\DI\ContainerFactory())->create($initial->config, $initial->dependencies);
        $container->get(\Componenta\App\Build\ApplicationBuildOrchestrator::class)->build();

        file_put_contents($root . '/src/Added.php', '<?php final class BuiltDiscoveryAdded {}');
        $built = ConfigFactory::create($paths, $definition, new Environment([]));
        expect(array_map(static fn (ClassInfo $info): string => $info->fullyQualifiedName, $built->discovered->toArray()))
            ->toBe(['BuiltDiscoveryOriginal']);

        $rebuild = (new \Componenta\DI\ContainerFactory())->create($built->config, $built->dependencies);
        $rebuild->get(\Componenta\App\Build\ApplicationBuildOrchestrator::class)->build();
        $after = ConfigFactory::create($paths, $definition, new Environment([]));
        expect(array_map(static fn (ClassInfo $info): string => $info->fullyQualifiedName, $after->discovered->toArray()))
            ->toContain('BuiltDiscoveryOriginal', 'BuiltDiscoveryAdded')->toHaveCount(2);
    } finally {
        spl_autoload_unregister($autoload);
        removeAppConfigFactoryRoot($root);
    }
});

it('uses source discovery when the prepared map is missing or unusable without writing a development cache', function (?string $artifact): void {
    $root = appConfigFactoryRoot('map_fallback');
    try {
        file_put_contents($root . '/src/Current.php', '<?php final class MapFallbackCurrent {}');
        $mapFile = $root . '/var/cache/build/current.json';
        if ($artifact !== null) {
            mkdir(dirname($mapFile), 0700, true);
            file_put_contents($mapFile, $artifact);
        }
        $result = ConfigFactory::create(new PathResolver($root), appConfigDefinition([]), new Environment([]));
        expect(array_map(static fn (ClassInfo $class): string => $class->fullyQualifiedName, $result->discovered->toArray()))
            ->toBe(['MapFallbackCurrent'])
            ->and(is_dir($root . '/var/cache/dev'))->toBeFalse();
        if ($artifact === null) {
            expect(is_dir($root . '/var'))->toBeFalse();
        } else {
            expect(file_get_contents($mapFile))->toBe($artifact);
        }
    } finally {
        removeAppConfigFactoryRoot($root);
    }
})->with([
    'missing' => [null],
    'wrong root' => ['<?php return null;'],
    'invalid declaration' => ['<?php return [["name" => "Incomplete"]];'],
    'invalid PHP' => ['<?php return [;'],
]);

it('lets the application override the builder list contributed by the App provider', function (): void {
    $root = appConfigFactoryRoot('builder_override');
    try {
        $result = ConfigFactory::create(new PathResolver($root), appConfigDefinition([
            new \Componenta\App\ConfigProvider(),
            static fn (): array => [
                \Componenta\App\ConfigKey::BUILDERS => \Componenta\Config\ConfigOverride::replace([]),
            ],
        ]), new Environment([]));
        $container = (new \Componenta\DI\ContainerFactory())->create($result->config, $result->dependencies);
        $container->get(\Componenta\App\Build\ApplicationBuildOrchestrator::class)->build();

        expect($result->config->get(\Componenta\App\ConfigKey::BUILDERS))->toBe([])
            ->and($container->get(\Componenta\App\Build\BuildStore::class)->load())->toBeInstanceOf(\Componenta\App\Build\PreparedBuild::class);
    } finally {
        removeAppConfigFactoryRoot($root);
    }
});

it('retains required provider validation when a definition loader changes caller-owned references', function (): void {
    $root = appConfigFactoryRoot('required_provider_policy');
    $paths = new PathResolver($root);
    $environment = new Environment([]);
    $store = ConfigFactory::buildStore($paths, $environment);
    $required = \Componenta\App\ConfigKey::CONFIG_PROVIDERS_ARTIFACT;
    $requiredArtifacts = [&$required];
    $definition = appConfigDefinition([new \Componenta\App\ConfigProvider()]);

    try {
        $build = $store->begin();
        $build->write(\Componenta\App\ConfigKey::DISCOVERY_ARTIFACT, []);
        $build->write(\Componenta\App\ConfigKey::CONFIG_PROVIDERS_ARTIFACT, ['']);
        $store->publish($build);

        $loadDefinition = static function () use (&$required, $definition): ConfigDefinition {
            $required = 'different.artifact';
            return $definition;
        };

        expect(fn () => ConfigFactory::create(
            $paths,
            $loadDefinition,
            $environment,
            requiredArtifacts: $requiredArtifacts,
        ))->toThrow(RuntimeException::class, 'Required configuration provider artifact has an invalid class list.');
        expect($required)->toBe('different.artifact');

        // The same malformed optional index still permits the documented fallback.
        expect(ConfigFactory::create($paths, $definition, $environment)->discovered->count())->toBe(0);
    } finally {
        removeAppConfigFactoryRoot($root);
    }
});


it('can recover using source discovery explicitly even when a prepared map still exists', function (): void {
    $root = appConfigFactoryRoot('forced_source');
    file_put_contents($root . '/src/Old.php', '<?php final class ForcedSourceOld {}');
    try {
        require $root . '/src/Old.php';
        $paths = new \Componenta\Stdlib\PathResolver($root);
        $definition = new \Componenta\App\Config\ConfigDefinition(
            providers: [new \Componenta\App\ConfigProvider()],
            discovery: new \Componenta\App\Config\DiscoveryDefinition(['src']),
        );
        $initial = \Componenta\App\Config\ConfigFactory::create($paths, $definition, new \Componenta\Config\Environment([]));
        $container = (new \Componenta\DI\ContainerFactory())->create($initial->config, $initial->dependencies);
        $container->get(\Componenta\App\Build\ApplicationBuildOrchestrator::class)->build();
        unlink($root . '/src/Old.php');
        file_put_contents($root . '/src/New.php', '<?php final class ForcedSourceNew {}');

        $recovered = \Componenta\App\Config\ConfigFactory::create(
            $paths, $definition, new \Componenta\Config\Environment(['APP_DISCOVERY_SOURCE' => '1']),
        );
        expect(array_map(static fn (\Componenta\Tokenizer\ClassInfo $info): string => $info->fullyQualifiedName, $recovered->discovered->toArray()))
            ->toBe(['ForcedSourceNew']);
        $recoveredContainer = (new \Componenta\DI\ContainerFactory())->create($recovered->config, $recovered->dependencies);
        expect($recoveredContainer->get(\Componenta\App\ConfigKey::DISCOVERY_PREPARED))->toBeFalse();
    } finally {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($root);
    }
});
