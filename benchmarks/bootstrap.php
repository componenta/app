<?php

declare(strict_types=1);

use Componenta\App\Boot\BootMethodInvocation;
use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\App\Config\ConfigDefinition;
use Componenta\App\Config\ConfigFactory;
use Componenta\App\Config\DiscoveryDefinition;
use Componenta\App\ConfigProvider;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Stdlib\PathResolver;

require dirname(__DIR__) . '/vendor/autoload.php';

final class AppBenchmarkState
{
    public static int $calls = 0;
    public static int $loads = 0;
}

if (($argv[1] ?? '') === 'sample') {
    $root = $argv[2];
    $mode = $argv[3];
    spl_autoload_register(static function (string $class) use ($root): void {
        if (preg_match('/\AAppBenchmarkClass[0-9]+\z/', $class) === 1) {
            ++AppBenchmarkState::$loads;
            require $root . '/src/' . $class . '.php';
        }
    });
    $paths = new PathResolver($root);
    $definition = new ConfigDefinition([new ConfigProvider()], new DiscoveryDefinition(['src']));
    $environment = new Environment(['APP_DISCOVERY_SOURCE' => $mode !== 'prepared']);
    $start = hrtime(true);
    $result = ConfigFactory::create($paths, $definition, $environment);
    $container = (new ContainerFactory())->create($result->config, $result->dependencies);

    if ($mode === 'build') {
        $container->get(ApplicationBuildOrchestrator::class)->build();
        exit(0);
    }

    $listener = $container->get(BootMethodInvocation::class);
    foreach ($container->get(ClassIteratorInterface::class) as $class) {
        $listener->handle($class);
    }
    $listener->finalize();
    echo json_encode([
        'ms' => (hrtime(true) - $start) / 1e6,
        'autoloads' => AppBenchmarkState::$loads,
        'calls' => AppBenchmarkState::$calls,
        'peak_bytes' => memory_get_peak_usage(true),
        'prepared' => $container->get(\Componenta\App\ConfigKey::DISCOVERY_PREPARED),
    ], JSON_THROW_ON_ERROR), PHP_EOL;
    exit(0);
}

$classes = filter_var($argv[1] ?? 500, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
$samples = filter_var($argv[2] ?? 7, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
if ($classes === false || $samples === false) {
    throw new InvalidArgumentException('Usage: php benchmarks/bootstrap.php [classes 1..10000] [samples 1..50]');
}
$root = sys_get_temp_dir() . '/componenta-app-bench-' . bin2hex(random_bytes(8));
mkdir($root . '/src', 0700, true);

function runSample(string $root, string $mode): string
{
    $process = proc_open([PHP_BINARY, __FILE__, 'sample', $root, $mode], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start benchmark subprocess.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || $output === false) {
        throw new RuntimeException('Benchmark subprocess failed: ' . $errors);
    }
    return $output;
}

function median(array $values): float
{
    sort($values, SORT_NUMERIC);
    $middle = intdiv(count($values), 2);
    return count($values) % 2 === 1
        ? $values[$middle]
        : ($values[$middle - 1] + $values[$middle]) / 2;
}

try {
    for ($i = 0; $i < $classes; ++$i) {
        $name = 'AppBenchmarkClass' . $i;
        $method = $i % 20 === 0
            ? '#[\Componenta\App\Boot\Boot] public static function boot(): void { ++\AppBenchmarkState::$calls; }'
            : '';
        file_put_contents($root . '/src/' . $name . '.php', '<?php final class ' . $name . ' { ' . $method . ' }');
    }
    runSample($root, 'build');
    $results = ['source' => [], 'prepared' => []];
    for ($i = 0; $i < $samples; ++$i) {
        foreach ($i % 2 === 0 ? ['source', 'prepared'] : ['prepared', 'source'] as $mode) {
            $sample = json_decode(runSample($root, $mode), true, flags: JSON_THROW_ON_ERROR);
            if ($sample['calls'] !== intdiv($classes + 19, 20) || $sample['prepared'] !== ($mode === 'prepared')) {
                throw new RuntimeException('Source and prepared benchmark behavior diverged.');
            }
            $results[$mode][] = $sample;
        }
    }
    printf("PHP %s, %d declarations, %d fresh processes per mode\n", PHP_VERSION, $classes, $samples);
    foreach ($results as $mode => $rows) {
        printf("%-9s median %.3f ms | median autoloads %.0f | median peak %.2f MiB\n",
            $mode, median(array_column($rows, 'ms')), median(array_column($rows, 'autoloads')),
            median(array_column($rows, 'peak_bytes')) / 1048576);
    }
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
