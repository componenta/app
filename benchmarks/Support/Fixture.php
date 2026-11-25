<?php

declare(strict_types=1);

namespace Componenta\App\Benchmarks;

use Closure;
use Componenta\App\AppInterface;
use Componenta\App\Boot\BootContext;
use Componenta\App\Boot\BootloaderInterface;
use Componenta\App\Boot\BootTargetAdapterInterface;
use Componenta\App\Boot\ScopedBootloaderSupport;
use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\App\Config\AttributeConfigProvider;
use Componenta\App\Config\ConfigDefinition;
use Componenta\App\Config\ConfigFactory;
use Componenta\App\Config\ConfigFactoryResult;
use Componenta\App\Config\DiscoveryDefinition;
use Componenta\App\ConfigKey;
use Componenta\App\ConfigProvider;
use Componenta\App\Scope;
use Componenta\Config\ConfigFactory as RuntimeConfigFactory;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Scope\ScopeInterface;
use Componenta\Scope\Scopes;
use Componenta\Stdlib\PathResolver;
use FilesystemIterator;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class BenchmarkState
{
    public static int $bootCalls = 0;
    public static int $autoloads = 0;

    public static function invoke(int $value): void
    {
        if ($value !== 42) {
            throw new LogicException('Invalid benchmark parameter.');
        }
    }
}

final class BenchmarkApp implements AppInterface
{
    public function configure(): void {}

    public function run(): int
    {
        return 17;
    }
}

final readonly class BenchmarkTarget
{
    public function __construct(private BenchmarkApp $app) {}

    public function configure(): void
    {
        $this->app->configure();
    }
}

final class BenchmarkTargetAdapter implements BootTargetAdapterInterface
{
    public function supports(ScopeInterface $scope): bool
    {
        return $scope->matches(Scope::HTTP);
    }

    public function create(AppInterface $app, ScopeInterface $scope): object
    {
        if (!$app instanceof BenchmarkApp) {
            throw new LogicException('Unexpected application.');
        }
        return new BenchmarkTarget($app);
    }
}

final class BenchmarkBootloader implements BootloaderInterface
{
    use ScopedBootloaderSupport;

    public Scopes $scopes {
        get => Scopes::of(Scope::HTTP);
    }

    public function boot(BootContext $context): void
    {
        $context->target(BenchmarkTarget::class)->configure();
    }
}

/** Test data and setup only. No timing or report generation lives here. */
final class Fixture
{
    private ?Closure $autoload = null;

    public function __construct(public readonly string $root) {}

    public static function create(int $count, bool $attributeProviders = false): self
    {
        $root = sys_get_temp_dir() . '/componenta-app-phpbench-' . bin2hex(random_bytes(10));
        if (!mkdir($root . '/src', 0700, true)) {
            throw new RuntimeException('Cannot create benchmark directory.');
        }
        $fixture = new self($root);
        try {
            for ($i = 0; $i < $count; ++$i) {
                $name = 'AppBenchmarkClass' . $i;
                $method = $i % 20 === 0
                    ? '#[\Componenta\App\Boot\Boot] public static function boot(): void { ++\Componenta\App\Benchmarks\BenchmarkState::$bootCalls; }'
                    : '';
                $attribute = '';
                if ($attributeProviders && $i % 20 === 0) {
                    $attribute = '#[\Componenta\App\Config\AsConfig] ';
                    $method .= ' public function __invoke(): array { return ["bench.discovered" => [self::class]]; }';
                }
                if (file_put_contents($root . '/src/' . $name . '.php', '<?php ' . $attribute . 'final class ' . $name . ' { ' . $method . ' }') === false) {
                    throw new RuntimeException('Cannot write benchmark fixture.');
                }
            }
            return $fixture;
        } catch (\Throwable $error) {
            $fixture->close();
            throw $error;
        }
    }

    public function registerAutoload(): void
    {
        $root = $this->root;
        $this->autoload = static function (string $class) use ($root): void {
            if (preg_match('/\AAppBenchmarkClass[0-9]+\z/', $class) === 1) {
                ++BenchmarkState::$autoloads;
                require $root . '/src/' . $class . '.php';
            }
        };
        spl_autoload_register($this->autoload);
    }

    public static function runtimeConfig(): array
    {
        return [
            ConfigKey::APPLICATIONS => [Scope::HTTP->value => BenchmarkApp::class],
            ConfigKey::BOOT_TARGET_ADAPTERS => [BenchmarkTargetAdapter::class],
            ConfigKey::BOOTLOADERS => [BenchmarkBootloader::class],
            'bench.value' => 42,
            ConfigKey::DEPENDENCIES => [ConfigKey::SERVICES => ['bench.entry' => 42]],
        ];
    }

    public static function runtimeContainer(): ContainerValue
    {
        $composition = (new RuntimeConfigFactory())->create(
            new Environment(['BENCH_VALUE' => '42']),
            new ConfigProvider(),
            self::runtimeConfig(...),
        );
        return (new ContainerFactory())->create($composition->config, $composition->dependencies);
    }

    public function configuration(bool $prepared, bool $attributeProviders = false): ConfigFactoryResult
    {
        return ConfigFactory::create(
            new PathResolver($this->root),
            new ConfigDefinition([
                new ConfigProvider(),
                new \Componenta\ClassFinder\ConfigProvider(),
                self::runtimeConfig(...),
                ...($attributeProviders ? [new AttributeConfigProvider()] : []),
            ], new DiscoveryDefinition(['src'])),
            new Environment(['APP_DISCOVERY_SOURCE' => !$prepared, 'BENCH_VALUE' => '42']),
        );
    }

    public function container(bool $prepared): ContainerValue
    {
        $result = $this->configuration($prepared);
        return (new ContainerFactory())->create($result->config, $result->dependencies);
    }

    /** Real build in another process, so the measured process starts with no fixture classes loaded. */
    public function prepare(): void
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'opcache.enable_cli=0', __DIR__ . '/prepare.php', $this->root],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot prepare benchmark artifacts.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Artifact preparation failed: ' . $output);
        }
    }

    public function assertArtifacts(bool $present): void
    {
        foreach ([ConfigKey::DISCOVERY_MAP_FILE, ConfigKey::BOOT_MAP_FILE] as $file) {
            clearstatcache(true, $this->root . '/' . $file);
            if (is_file($this->root . '/' . $file) !== $present) {
                throw new LogicException('Unexpected artifact state: ' . $file);
            }
        }
    }

    public function close(): void
    {
        if ($this->autoload !== null) {
            spl_autoload_unregister($this->autoload);
            $this->autoload = null;
        }
        if (!is_dir($this->root)) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }
}
