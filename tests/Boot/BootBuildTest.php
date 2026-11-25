<?php

declare(strict_types=1);

use Componenta\App\Boot\Boot;
use Componenta\App\Boot\BootMethodInvocation;
use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\App\Config\ConfigDefinition;
use Componenta\App\Config\ConfigFactory;
use Componenta\App\Config\DiscoveryDefinition;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Env;
use Componenta\DI\ContainerFactory;
use Componenta\Stdlib\PathResolver;

final class BootBuildValue
{
    public static int $constructions = 0;
    public static ?Throwable $failure = null;
    public int $uses = 0;
    public function __construct()
    {
        ++self::$constructions;
        if (self::$failure !== null) { throw self::$failure; }
    }
}

class BootBuildParent
{
    public static array $calls = [];
    #[Boot(priority: -1)]
    public static function inherited(): void { self::$calls[] = 'inherited'; }
}

final class BootBuildChild extends BootBuildParent
{
    #[Boot(priority: 5, params: ['name' => new Env('BOOT_NAME'), 'value' => new BootBuildValue()])]
    #[Boot(priority: 5, params: ['name' => 'second', 'value' => new BootBuildValue()])]
    public static function start(string $name, BootBuildValue $value): void
    {
        self::$calls[] = $name . ':' . ++$value->uses;
    }
}

it('builds boot metadata without instantiating attributes and preserves native invocation semantics', function (): void {
    $root = sys_get_temp_dir() . '/componenta_boot_build_' . bin2hex(random_bytes(8));
    mkdir($root . '/src', 0700, true);
    file_put_contents($root . '/src/Boot.php', '<?php final class BootBuildChild {}');
    $load = static function (string $name) use ($root) {
        $result = ConfigFactory::create(new PathResolver($root), new ConfigDefinition(
            providers: [new \Componenta\App\ConfigProvider()],
            discovery: new DiscoveryDefinition(['src']),
        ), new Environment(['BOOT_NAME' => $name]));
        return (new ContainerFactory())->create($result->config, $result->dependencies);
    };
    $boot = static function ($container): array {
        BootBuildParent::$calls = [];
        $listener = $container->get(BootMethodInvocation::class);
        foreach ($container->get(ClassIteratorInterface::class) as $class) { $listener->handle($class); }
        $listener->finalize();
        return BootBuildParent::$calls;
    };
    try {
        expect($boot($load('source')))->toBe(['source:1', 'second:1', 'inherited']);
        BootBuildValue::$constructions = 0;
        $builder = $load('build')->get(ApplicationBuildOrchestrator::class);
        expect(BootBuildValue::$constructions)->toBe(0);
        $builder->build();
        expect(BootBuildValue::$constructions)->toBe(0)
            ->and(is_file($root . '/var/cache/build/boot.php'))->toBeTrue();
        expect($boot($load('built')))->toBe(['built:1', 'second:1', 'inherited'])
            ->and($boot($load('again')))->toBe(['again:1', 'second:1', 'inherited']);
        $failure = BootBuildValue::$failure = new DomainException('Attribute parameter failed.');
        try {
            $boot($load('failing'));
            test()->fail('Native attribute construction must still fail.');
        } catch (DomainException $caught) {
            expect($caught)->toBe($failure);
        } finally { BootBuildValue::$failure = null; }

        // A structurally valid map from another build must not suppress boot methods.
        $currentMap = require $root . '/var/cache/build/boot.php';
        $oldMap = ['version' => $currentMap['version'], 'generation' => str_repeat('f', 32), 'payload' => ['BootBuildChild' => []]];
        file_put_contents($root . '/var/cache/build/boot.php', '<?php return ' . var_export($oldMap, true) . ';');
        expect($boot($load('mixed-generation')))->toBe(['mixed-generation:1', 'second:1', 'inherited']);

        foreach ([
            '<?php return false;',
            '<?php return ' . var_export(array_replace($currentMap, ['payload' => ['BootBuildChild' => [false]]]), true) . ';',
            '<?php return ' . var_export(array_replace($currentMap, ['payload' => ['BootBuildChild' => ['start', 'start']]]), true) . ';',
            '<?php return ' . var_export(array_replace($currentMap, ['payload' => ['BootBuildChild' => ['missingMethod']]]), true) . ';',
            '<?php return [;',
            '<?php throw new LogicException("Invalid map");',
        ] as $invalidMap) {
            file_put_contents($root . '/var/cache/build/boot.php', $invalidMap);
            expect($boot($load('fallback')))->toBe(['fallback:1', 'second:1', 'inherited']);
        }
        file_put_contents($root . '/var/cache/build/boot.php', '<?php return ["BootBuildChild" => []];');
        unlink($root . '/var/cache/build/classes.php');
        expect($boot($load('source-again')))->toBe(['source-again:1', 'second:1', 'inherited']);
        $load('rebuild')->get(ApplicationBuildOrchestrator::class)->build();
        expect($boot($load('rebuilt')))->toBe(['rebuilt:1', 'second:1', 'inherited']);
    } finally {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($root);
    }
});
