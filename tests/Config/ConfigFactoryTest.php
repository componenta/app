<?php

declare(strict_types=1);

use Componenta\App\Build\ApplicationBuilder;
use Componenta\App\Config\AttributeConfigProvider;
use Componenta\App\Config\ComposerPackageConfigProvider;
use Componenta\App\Config\ConfigDefinition;
use Componenta\App\Config\ConfigFactory;
use Componenta\App\Config\DiscoveryDefinition;
use Componenta\App\Discovery\StaticDiscoveryExtractorInterface;
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

final class AppConfigFactorySemanticExtractor implements StaticDiscoveryExtractorInterface
{
    public int $calls = 0;

    public function key(): string
    {
        return 'test-map';
    }

    public function extract(ClassIteratorInterface $classes): array
    {
        $this->calls++;
        $names = [];

        foreach ($classes->toArray() as $class) {
            if (!$class instanceof ClassInfo) {
                throw new RuntimeException('Static discovery must expose ClassInfo instances.');
            }

            $names[] = $class->fullyQualifiedName;
        }

        return ['semantic.map' => $names];
    }
}

final class AppConfigFactorySemanticOnceExample
{
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

it('builds data-only artifacts without invoking config providers and production materializes them at runtime', function (): void {
    $root = appConfigFactoryRoot('artifact');
    file_put_contents($root . '/src/Example.php', "<?php\n\ndeclare(strict_types=1);\n\nfinal class BuiltDiscoveryExample {}\n");
    $providersFile = $root . '/providers.php';
    file_put_contents($providersFile, '<?php return [' . var_export(AppConfigFactoryArtifactProvider::class, true) . '];');
    $runtimeCalls = 0;
    $definition = appConfigDefinition([
        new ComposerPackageConfigProvider($providersFile),
        static function () use (&$runtimeCalls): array {
            $runtimeCalls++;

            return ['provider_order' => ['application']];
        },
    ]);
    $paths = new PathResolver($root);
    AppConfigFactoryArtifactProvider::$calls = 0;
    AppConfigFactoryArtifactProvider::$constructions = 0;

    try {
        $build = (new ApplicationBuilder())->build($paths, $definition);

        expect(AppConfigFactoryArtifactProvider::$constructions)->toBe(0)
            ->and(AppConfigFactoryArtifactProvider::$calls)->toBe(0)
            ->and($runtimeCalls)->toBe(0)
            ->and($build->directory)->toBeDirectory()
            ->and($build->manifest)->toBeFile();

        unlink($providersFile);
        unlink($root . '/src/Example.php');

        $result = ConfigFactory::create(
            paths: $paths,
            definition: $definition,
            environment: new Environment(['APP_ENV' => 'production']),
        );

        expect(AppConfigFactoryArtifactProvider::$constructions)->toBe(1)
            ->and(AppConfigFactoryArtifactProvider::$calls)->toBe(1)
            ->and($runtimeCalls)->toBe(1)
            ->and($result->config->get('provider_order'))->toBe(['package', 'application'])
            ->and($result->discovered)->toHaveCount(1)
            ->and($result->diagnostics)->toBe([]);

        $services = $result->dependencies->sections[ConfigKey::SERVICES] ?? null;
        if (!is_array($services)) {
            throw new RuntimeException('ConfigFactory must expose a services dependency section.');
        }

        $runtimeClosure = $services['runtime.closure'] ?? null;
        expect($runtimeClosure)->toBeInstanceOf(Closure::class);
        if (!$runtimeClosure instanceof Closure) {
            throw new RuntimeException('Runtime service definition must remain a closure.');
        }

        expect($runtimeClosure())->toBe('alive');
    } finally {
        removeAppConfigFactoryRoot($root);
    }
});

it('preserves repeated package provider occurrences and falls back before provider materialization', function (): void {
    $root = appConfigFactoryRoot('providers-fallback');
    file_put_contents($root . '/src/Example.php', "<?php\n\ndeclare(strict_types=1);\n\nfinal class ProviderFallbackExample {}\n");
    $providersFile = $root . '/providers.php';
    file_put_contents(
        $providersFile,
        '<?php return ['
        . var_export(AppConfigFactoryArtifactProvider::class, true)
        . ', '
        . var_export(AppConfigFactoryArtifactProvider::class, true)
        . '];',
    );
    $paths = new PathResolver($root);
    $definition = appConfigDefinition([new ComposerPackageConfigProvider($providersFile)]);
    AppConfigFactoryArtifactProvider::$constructions = 0;
    AppConfigFactoryArtifactProvider::$calls = 0;

    try {
        $build = (new ApplicationBuilder())->build($paths, $definition);
        file_put_contents($build->directory . '/providers.0.json', '{corrupt');

        $result = ConfigFactory::create(
            paths: $paths,
            definition: $definition,
            environment: new Environment(['APP_ENV' => 'production']),
        );

        expect(AppConfigFactoryArtifactProvider::$constructions)->toBe(2)
            ->and(AppConfigFactoryArtifactProvider::$calls)->toBe(2)
            ->and($result->config->get('provider_order'))->toBe(['package', 'package'])
            ->and($result->diagnostics)->toHaveCount(1)
            ->and($result->diagnostics[0])->toContain('providers.0');
    } finally {
        removeAppConfigFactoryRoot($root);
    }
});

it('publishes immutable content-addressed generations', function (): void {
    $root = appConfigFactoryRoot('immutable');
    $source = $root . '/src/Example.php';
    file_put_contents($source, "<?php\n\ndeclare(strict_types=1);\n\nfinal class ImmutableGenerationOne {}\n");
    $paths = new PathResolver($root);
    $definition = appConfigDefinition([static fn (): array => []]);

    try {
        $first = (new ApplicationBuilder())->build($paths, $definition);
        $same = (new ApplicationBuilder())->build($paths, $definition);
        expect($same->generation)->toBe($first->generation)
            ->and($same->directory)->toBe($first->directory);

        file_put_contents($source, "<?php\n\ndeclare(strict_types=1);\n\nfinal class ImmutableGenerationTwo {}\n");
        $second = (new ApplicationBuilder())->build($paths, $definition);

        expect($second->generation)->not->toBe($first->generation)
            ->and($first->directory)->toBeDirectory()
            ->and($second->directory)->toBeDirectory();

        $manifest = file_get_contents($root . '/var/cache/build/current.json');
        if (!is_string($manifest)) {
            throw new RuntimeException('Failed to read the current build manifest.');
        }

        $current = json_decode($manifest, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($current)) {
            throw new RuntimeException('The current build manifest must decode to an array.');
        }

        expect($current['generation'])->toBe($second->generation);
    } finally {
        removeAppConfigFactoryRoot($root);
    }
});

it('verifies an artifact section before materialization and falls back to source discovery when it is corrupt', function (): void {
    $root = appConfigFactoryRoot('fallback');
    file_put_contents($root . '/src/Example.php', "<?php\n\ndeclare(strict_types=1);\n\nfinal class CorruptArtifactFallbackExample {}\n");
    $paths = new PathResolver($root);
    $definition = appConfigDefinition([static fn (): array => ['source' => true]]);

    try {
        $build = (new ApplicationBuilder())->build($paths, $definition);
        file_put_contents($build->directory . '/classes.json', '{corrupt');

        $result = ConfigFactory::create(
            paths: $paths,
            definition: $definition,
            environment: new Environment(['APP_ENV' => 'production']),
        );

        expect($result->discovered)->toHaveCount(1)
            ->and($result->config->get('source'))->toBeTrue()
            ->and($result->diagnostics)->toHaveCount(1)
            ->and($result->diagnostics[0])->toContain('classes');
    } finally {
        removeAppConfigFactoryRoot($root);
    }
});

it('verifies and falls back each package semantic extractor independently', function (): void {
    $root = appConfigFactoryRoot('semantic');
    file_put_contents($root . '/src/Example.php', "<?php\n\ndeclare(strict_types=1);\n\nfinal class SemanticExtractorExample {}\n");
    $paths = new PathResolver($root);
    $extractor = new AppConfigFactorySemanticExtractor();
    $definition = new ConfigDefinition(
        providers: [static fn (): array => []],
        discovery: new DiscoveryDefinition(
            directories: ['src'],
            extractors: [$extractor],
        ),
    );

    try {
        $build = (new ApplicationBuilder())->build($paths, $definition);
        expect($extractor->calls)->toBe(1);

        $fromArtifact = ConfigFactory::create(
            paths: $paths,
            definition: $definition,
            environment: new Environment(['APP_ENV' => 'production']),
        );

        expect($extractor->calls)->toBe(1)
            ->and($fromArtifact->config->get('semantic.map'))->toBe(['SemanticExtractorExample'])
            ->and($fromArtifact->diagnostics)->toBe([]);

        file_put_contents($build->directory . '/semantic.test-map.json', '{corrupt');
        $fromSource = ConfigFactory::create(
            paths: $paths,
            definition: $definition,
            environment: new Environment(['APP_ENV' => 'production']),
        );

        expect($extractor->calls)->toBe(2)
            ->and($fromSource->config->get('semantic.map'))->toBe(['SemanticExtractorExample'])
            ->and($fromSource->diagnostics)->toHaveCount(1)
            ->and($fromSource->diagnostics[0])->toContain('semantic.test-map');
    } finally {
        removeAppConfigFactoryRoot($root);
    }
});

it('applies each semantic extractor contribution once when attribute provider occurs more than once', function (): void {
    $root = appConfigFactoryRoot('semantic-once');
    file_put_contents(
        $root . '/src/Example.php',
        "<?php\n\ndeclare(strict_types=1);\n\nfinal class AppConfigFactorySemanticOnceExample {}\n",
    );
    $paths = new PathResolver($root);
    $extractor = new AppConfigFactorySemanticExtractor();
    $definition = new ConfigDefinition(
        providers: [
            new AttributeConfigProvider(),
            new AttributeConfigProvider(),
        ],
        discovery: new DiscoveryDefinition(
            directories: ['src'],
            extractors: [$extractor],
        ),
    );

    try {
        $result = ConfigFactory::create(
            paths: $paths,
            definition: $definition,
            environment: new Environment(['APP_ENV' => 'development']),
        );

        expect($extractor->calls)->toBe(1)
            ->and($result->config->get('semantic.map'))->toBe([AppConfigFactorySemanticOnceExample::class]);
    } finally {
        removeAppConfigFactoryRoot($root);
    }
});
