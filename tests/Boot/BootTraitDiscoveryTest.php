<?php

declare(strict_types=1);

use Componenta\App\Boot\BootContext;
use Componenta\App\Boot\ClassDiscoveryBootloader;
use Componenta\App\Build\ApplicationBuildRunner;
use Componenta\App\Build\BuildStore;
use Componenta\App\Build\PreparedBuild;
use Componenta\App\Config\ConfigDefinition;
use Componenta\App\Config\ConfigFactory;
use Componenta\App\Config\DiscoveryDefinition;
use Componenta\App\ConfigKey;
use Componenta\App\Scope;
use Componenta\App\Tests\BootTraits\FirstHost;
use Componenta\App\Tests\BootTraits\SecondHost;
use Componenta\App\Tests\BootTraits\Startup;
use Componenta\App\Tests\BootTraits\Trace;
use Componenta\App\Tests\BootTraits\UnusedStartup;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Stdlib\PathResolver;

require_once __DIR__ . '/../Fixtures/BootTraitDiscovery.php';

function bootTraitDiscoveryRoot(): string
{
    $root = sys_get_temp_dir() . '/componenta_boot_traits_' . bin2hex(random_bytes(8));
    mkdir($root . '/src', 0700, true);
    copy(__DIR__ . '/../Fixtures/BootTraitDiscovery.php', $root . '/src/Startup.php');
    Trace::$calls = [];
    return $root;
}

function bootTraitDiscoveryDefinition(): ConfigDefinition
{
    return new ConfigDefinition(
        providers: [new Componenta\ClassFinder\ConfigProvider(), new Componenta\App\ConfigProvider()],
        discovery: new DiscoveryDefinition(['src']),
    );
}

function bootTraitDiscoveryContainer(PathResolver $paths, bool $source = false): ContainerValue
{
    $composition = ConfigFactory::create($paths, bootTraitDiscoveryDefinition(...), new Environment([]), source: $source);
    return (new ContainerFactory())->create($composition->config, $composition->dependencies);
}

function runBootTraitDiscovery(ContainerValue $container): void
{
    $container->get(ClassDiscoveryBootloader::class)->boot(new BootContext($container, Scope::CLI, new stdClass()));
}

function removeBootTraitDiscoveryRoot(string $root): void
{
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
    Trace::$calls = [];
}

it('boots trait methods through each consuming class in source and prepared applications', function (bool $prepared): void {
    $root = bootTraitDiscoveryRoot();
    $paths = new PathResolver($root);
    try {
        if ($prepared) {
            $build = ApplicationBuildRunner::build($paths, bootTraitDiscoveryDefinition(...), new Environment([]));
            expect(Trace::$calls)->toBe([])
                ->and($build->read(ConfigKey::BOOT_ARTIFACT))->not->toHaveKey(Startup::class)
                ->not->toHaveKey(UnusedStartup::class);
        }

        runBootTraitDiscovery(bootTraitDiscoveryContainer($paths, source: !$prepared));

        expect(Trace::$calls)->toBe([
            [FirstHost::class, 'instance'],
            [SecondHost::class, 'instance'],
            [FirstHost::class, 'static'],
            [SecondHost::class, 'static'],
        ]);
    } finally {
        removeBootTraitDiscoveryRoot($root);
    }
})->with(['source' => false, 'prepared' => true]);

it('ignores standalone trait entries from earlier prepared boot maps', function (): void {
    $root = bootTraitDiscoveryRoot();
    $paths = new PathResolver($root);
    try {
        $original = ApplicationBuildRunner::build($paths, bootTraitDiscoveryDefinition(...), new Environment([]));
        $store = new BuildStore($paths->resolve(ConfigKey::DEFAULT_CACHE_BUILD_DIR));
        $legacy = $store->begin();
        foreach ([ConfigKey::DISCOVERY_ARTIFACT, ConfigKey::CONFIG_PROVIDERS_ARTIFACT] as $artifact) {
            $legacy->write($artifact, $original->read($artifact));
        }
        $legacy->write(ConfigKey::BOOT_ARTIFACT, [
            ...$original->read(ConfigKey::BOOT_ARTIFACT),
            Startup::class => ['instanceStart', 'staticStart'],
            UnusedStartup::class => ['unusedStart'],
        ]);
        $published = $store->publish($legacy);
        $container = bootTraitDiscoveryContainer($paths);

        expect($container->get(ConfigKey::PREPARED_BUILD, PreparedBuild::class)->generation)->toBe($published->generation);
        runBootTraitDiscovery($container);

        expect(Trace::$calls)->toBe([
            [FirstHost::class, 'instance'],
            [SecondHost::class, 'instance'],
            [FirstHost::class, 'static'],
            [SecondHost::class, 'static'],
        ]);
    } finally {
        removeBootTraitDiscoveryRoot($root);
    }
});
