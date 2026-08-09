<?php

declare(strict_types=1);

use Componenta\App\Boot\BootContext;
use Componenta\App\Boot\ClassDiscoveryBootloader;
use Componenta\App\Discovery\Compile\CompileCache;
use Componenta\App\Discovery\ListenerCompiler;
use Componenta\App\Scope;
use Componenta\ClassFinder\ClassIterator;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\ClassFinder\ClassListenerInterface;
use Componenta\ClassFinder\ClassListenerNotifier;
use Componenta\ClassFinder\ClassListenerProviderInterface;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\Tokenizer\ClassInfo;
use Psr\Container\ContainerInterface;

it('never falls back to class scanning in production when the restore cache is absent', function (): void {
    $listener = new class implements ClassListenerInterface {
        public int $handled = 0;

        public function handle(ClassInfo $info): void
        {
            ++$this->handled;
        }
    };
    $provider = new class ($listener) implements ClassListenerProviderInterface {
        public function __construct(private readonly ClassListenerInterface $listener) {}

        public function getClassListeners(): iterable
        {
            return [$this->listener];
        }
    };
    $container = new class ([
        ClassIteratorInterface::class => new ClassIterator([
            __FILE__ => new ClassInfo(stdClass::class),
        ]),
        ClassListenerNotifier::class => new ClassListenerNotifier($provider),
    ]) implements ContainerInterface {
        public function __construct(private readonly array $entries) {}

        public function get(string $id): mixed
        {
            return $this->entries[$id] ?? throw new RuntimeException($id);
        }

        public function has(string $id): bool
        {
            return array_key_exists($id, $this->entries);
        }
    };
    $context = new BootContext(
        new ContainerValue(
            $container,
            new Config([], new Environment(['APP_ENV' => 'production'])),
        ),
        Scope::HTTP,
        new stdClass(),
    );

    (new ClassDiscoveryBootloader())->boot($context);

    expect($listener->handled)->toBe(0);
});

it('persists no discovery section when the scan and contributors are empty', function (): void {
    $root = sys_get_temp_dir() . '/componenta_empty_discovery_' . bin2hex(random_bytes(4));
    mkdir($root);
    $baselineFile = $root . '/discovery.php';
    $cacheFile = $root . '/compile.php';
    file_put_contents($baselineFile, '<?php return [];');

    $provider = new class implements ClassListenerProviderInterface {
        public function getClassListeners(): iterable
        {
            return [];
        }
    };
    $iterator = new ClassIterator([]);
    $compileCache = new CompileCache($cacheFile, $baselineFile);

    $container = new class ([
        ClassIteratorInterface::class => $iterator,
        ClassListenerNotifier::class => new ClassListenerNotifier($provider),
        ListenerCompiler::class => new ListenerCompiler($provider),
        CompileCache::class => $compileCache,
    ]) implements ContainerInterface {
        public function __construct(private readonly array $entries) {}

        public function get(string $id): mixed
        {
            return $this->entries[$id] ?? throw new RuntimeException($id);
        }

        public function has(string $id): bool
        {
            return array_key_exists($id, $this->entries);
        }
    };

    $context = new BootContext(
        new ContainerValue($container, new Config([])),
        Scope::HTTP,
        new stdClass(),
    );

    try {
        (new ClassDiscoveryBootloader())->boot($context);

        expect($cacheFile)->toBeFile()
            ->and(require $cacheFile)->toBe([]);
    } finally {
        foreach ([$cacheFile, $cacheFile . '.lock', $baselineFile] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($root)) {
            rmdir($root);
        }
    }
});
