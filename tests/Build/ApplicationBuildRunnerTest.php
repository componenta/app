<?php

declare(strict_types=1);

use Componenta\App\Boot\Boot;
use Componenta\App\Build\ApplicationBuildRunner;
use Componenta\App\Build\ApplicationBuilderInterface;
use Componenta\App\Build\BuildContext;
use Componenta\App\Build\BuildStore;
use Componenta\App\Config\AsConfig;
use Componenta\App\Config\AttributeConfigProvider;
use Componenta\App\Config\ConfigDefinition;
use Componenta\App\Config\ConfigFactory;
use Componenta\App\Config\DiscoveryDefinition;
use Componenta\App\ConfigKey;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Stdlib\PathResolver;

#[AsConfig]
final class BuildRunnerInitialProvider
{
    public static string $value = 'initial';
    public static array $invocations = [];

    public function __invoke(): iterable
    {
        self::$invocations[] = getcwd();
        yield ['provider.value' => self::$value, 'order' => ['first']];
        yield ['order' => ['second']];
    }

    #[Boot]
    public static function boot(): never
    {
        throw new LogicException('Application boot effects must not run during build.');
    }
}

#[AsConfig]
final class BuildRunnerAddedProvider
{
    public function __invoke(): array
    {
        return [ConfigKey::BUILDERS => [BuildRunnerAddedBuilder::class], 'order' => ['added']];
    }
}

final class BuildRunnerAddedBuilder implements ApplicationBuilderInterface
{
    public function build(BuildContext $build): void
    {
        $build->write('test.added', ['registered' => true]);
    }
}

it('builds with fresh providers before container creation without executing boot methods', function (): void {
    $root = sys_get_temp_dir() . '/app_source_runner_' . bin2hex(random_bytes(8));
    mkdir($root . '/src', 0700, true);
    file_put_contents($root . '/src/Initial.php', '<?php final class BuildRunnerInitialProvider {}');
    file_put_contents($root . '/project-marker', 'project');
    $paths = new PathResolver($root);
    $definition = new ConfigDefinition(
        [new Componenta\App\ConfigProvider(), new AttributeConfigProvider()],
        new DiscoveryDefinition(['src']),
    );
    $environment = new Environment(['APP_ENV' => 'production', 'APP_BUILD_ID' => 'release']);
    $required = [ConfigKey::DISCOVERY_ARTIFACT, ConfigKey::CONFIG_PROVIDERS_ARTIFACT];
    $previousDirectory = getcwd();
    BuildRunnerInitialProvider::$invocations = [];
    BuildRunnerInitialProvider::$value = 'initial';

    try {
        $first = ApplicationBuildRunner::build($paths, static function () use ($definition) {
            if (file_get_contents('project-marker') !== 'project') {
                throw new LogicException('Build must use the application working directory.');
            }
            return $definition;
        }, $environment, $required);
        expect(getcwd())->toBe($previousDirectory)
            ->and(BuildRunnerInitialProvider::$invocations)->toBe([$root])
            ->and($first->read(ConfigKey::CONFIG_PROVIDERS_ARTIFACT))->toBe([BuildRunnerInitialProvider::class]);

        BuildRunnerInitialProvider::$value = 'runtime';
        $runtime = ConfigFactory::create($paths, $definition, $environment);
        expect($runtime->config->get('provider.value'))->toBe('runtime')
            ->and($runtime->config->get('order'))->toBe(['first', 'second']);
        file_put_contents($root . '/src/Added.php', '<?php final class BuildRunnerAddedProvider {}');
        expect(ConfigFactory::create($paths, $definition, $environment)->config->get('order'))->toBe(['first', 'second']);

        // The normal prepared bootstrap cannot see the new provider yet; the build runner must.
        $next = ApplicationBuildRunner::build($paths, $definition, $environment, [...$required, 'test.added']);
        expect($next->generation)->not->toBe($first->generation)
            ->and($next->read('test.added'))->toBe(['registered' => true])
            ->and(ConfigFactory::create($paths, $definition, $environment)->config->get('order'))->toBe(['added', 'first', 'second']);

        $source = ConfigFactory::create($paths, $definition, $environment, source: true);
        $container = (new ContainerFactory())->create($source->config, $source->dependencies);
        expect($container->has(ConfigKey::PREPARED_BUILD))->toBeFalse();

        ApplicationBuildRunner::clean($paths, $environment);
        expect((new BuildStore($root . '/var/cache/build', 'release'))->load())->toBeNull()
            ->and($first->read(ConfigKey::CONFIG_PROVIDERS_ARTIFACT))->toBe([BuildRunnerInitialProvider::class])
            ->and(getcwd())->toBe($previousDirectory);
    } finally {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($root);
    }
});

it('uses the provider index without traversing or reflecting unrelated declarations', function (): void {
    $classes = new ClassIterator((static function (): Generator {
        throw new LogicException('Prepared provider lookup must not scan all classes.');
        yield;
    })());
    $provider = (new AttributeConfigProvider($classes))->withPreparedProviders([BuildRunnerInitialProvider::class]);
    BuildRunnerInitialProvider::$value = 'first-runtime';
    expect(iterator_to_array($provider(), false))->toBe([
        ['provider.value' => 'first-runtime', 'order' => ['first']],
        ['order' => ['second']],
    ]);
    BuildRunnerInitialProvider::$value = 'second-runtime';
    expect(iterator_to_array($provider(), false)[0]['provider.value'])->toBe('second-runtime');
});

it('restores the caller directory when source configuration fails', function (): void {
    $root = sys_get_temp_dir() . '/app_source_failure_' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    $previous = getcwd();
    $failure = new DomainException('provider failed');
    try {
        try {
            ApplicationBuildRunner::build(new PathResolver($root), static function () use ($failure): never { throw $failure; });
            test()->fail('The source failure must propagate.');
        } catch (DomainException $caught) {
            expect($caught)->toBe($failure)->and(getcwd())->toBe($previous);
        }
    } finally { rmdir($root); }
});

it('does not bypass required artifact readers when core discovery is invalid', function (): void {
    $root = sys_get_temp_dir() . '/app_required_discovery_' . bin2hex(random_bytes(8));
    mkdir($root . '/src', 0700, true);
    file_put_contents($root . '/src/Fresh.php', '<?php final class RequiredDiscoveryFresh {}');
    $paths = new PathResolver($root);
    $definition = new ConfigDefinition([new Componenta\App\ConfigProvider()], new DiscoveryDefinition(['src']));
    $environment = new Environment([]);
    $store = ConfigFactory::buildStore($paths, $environment);
    try {
        $build = $store->begin();
        $build->write(ConfigKey::DISCOVERY_ARTIFACT, ['malformed']);
        $build->write('test.required', [], 999);
        $store->publish($build);
        expect(fn () => ConfigFactory::create($paths, $definition, $environment, requiredArtifacts: ['test.required']))
            ->toThrow(RuntimeException::class, 'required artifacts prohibit');
        expect(ConfigFactory::create($paths, $definition, $environment)->discovered->count())->toBe(1);

        $empty = $store->begin();
        $empty->write(ConfigKey::DISCOVERY_ARTIFACT, []);
        $empty->write('test.required', []);
        $store->publish($empty);
        expect(ConfigFactory::create($paths, $definition, $environment, requiredArtifacts: ['test.required'])->discovered->count())->toBe(0);
    } finally {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($root);
    }
});

it('rejects a runtime source override conflicting with required artifacts but permits explicit preparation', function (): void {
    $root = sys_get_temp_dir() . '/app_required_source_' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    $paths = new PathResolver($root);
    $definition = new ConfigDefinition([
        new Componenta\App\ConfigProvider(),
        static fn () => [ConfigKey::BUILDERS => [BuildRunnerAddedBuilder::class]],
    ]);
    $environment = new Environment(['APP_DISCOVERY_SOURCE' => '1']);
    try {
        expect(fn () => ConfigFactory::create($paths, $definition, $environment, requiredArtifacts: ['test.added']))
            ->toThrow(RuntimeException::class, 'conflicts with required artifacts');
        $prepared = ApplicationBuildRunner::build($paths, $definition, $environment, requiredArtifacts: ['test.added']);
        expect($prepared->read('test.added'))->toBe(['registered' => true]);
    } finally {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($root);
    }
});
