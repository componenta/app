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
