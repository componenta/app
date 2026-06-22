<?php

declare(strict_types=1);

namespace Componenta\App\Discovery\Compile;

use Componenta\App\Cache\AtomicFile;
use Componenta\ClassFinder\Compile\CompileResult;
use Componenta\ClassFinder\Compile\ListenerCompilerInterface;
use Componenta\ClassFinder\FinalizableListenerInterface;
use Componenta\ClassFinder\FinalizationStateInterface;
use RuntimeException;

/**
 * Runs all registered {@see ListenerCompilerInterface}s against every
 * listener and aggregates their {@see CompileResult}s into a single
 * config delta (plus sidecar files on disk).
 *
 * Visitor + Strategy: the orchestrator is passive with respect to listener
 * types; adding a new type means shipping a new compiler and registering
 * it by returning its class-string from that package's
 * `ConfigProvider::getConfig()` under {@see ConfigKey::LISTENER_COMPILERS}
 * (numeric list entries are concatenated via `config_merge`, so multiple
 * packages contribute without stepping on one another).
 *
 * The command-line entry point (`discovery:compile`) just hands its
 * listeners to this class.
 *
 * Listeners without a matching compiler are skipped silently - that is
 * how third-party or `#[DevOnly]` helpers opt out of being serialised.
 */
final readonly class DiscoveryCompiler
{
    /**
     * @var list<ListenerCompilerInterface>
     *
     * Stored as a concrete list so the inner loop in {@see compile()} can
     * be entered once per outer listener without worrying about whether the
     * caller passed a Generator (which would be consumed on first outer
     * iteration and leave later listeners unprocessed).
     */
    private array $compilers;

    /**
     * @param iterable<ListenerCompilerInterface> $compilers Normalised
     *        internally to a list; a Generator is materialised eagerly.
     */
    public function __construct(iterable $compilers)
    {
        $this->compilers = is_array($compilers)
            ? array_values($compilers)
            : iterator_to_array($compilers, preserve_keys: false);
    }

    /**
     * @param iterable<object> $listeners
     *
     * @return array<string, mixed> Config delta keyed by the {@see CompileResult::$configKey} each compiler produced.
     */
    public function compile(iterable $listeners, string $cacheDir): array
    {
        $this->ensureCacheDir($cacheDir);

        $config = [];

        foreach ($listeners as $listener) {
            $compiler = $this->compilerFor($listener);

            if ($compiler === null) {
                continue;
            }

            $this->assertReadyForCompilation($listener);

            $result = $compiler->compile($listener, $cacheDir);

            if ($result->configKey !== null) {
                $config[$result->configKey] = $result->configValue;
            }

            foreach ($result->files as $path => $contents) {
                $this->writeFile($path, $contents);
            }
        }

        return $config;
    }

    private function compilerFor(object $listener): ?ListenerCompilerInterface
    {
        foreach ($this->compilers as $compiler) {
            if ($compiler->supports($listener)) {
                // First match wins - prevents duplicate output if two
                // compilers happen to `supports()` the same listener.
                return $compiler;
            }
        }

        return null;
    }

    private function assertReadyForCompilation(object $listener): void
    {
        if (!$listener instanceof FinalizableListenerInterface) {
            return;
        }

        if (!$listener instanceof FinalizationStateInterface) {
            throw new RuntimeException(sprintf(
                'Cannot compile %s: finalizable listener must implement %s.',
                $listener::class,
                FinalizationStateInterface::class,
            ));
        }

        if (!$listener->finalized) {
            throw new RuntimeException(sprintf(
                'Cannot compile %s before it is finalized.',
                $listener::class,
            ));
        }
    }

    private function ensureCacheDir(string $cacheDir): void
    {
        if ($cacheDir === '') {
            return;
        }

        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0o755, recursive: true) && !is_dir($cacheDir)) {
            throw new RuntimeException("Unable to create compile cache directory: {$cacheDir}");
        }
    }

    /**
     * Atomic write: stage through a tmp path under the same directory and
     * rename onto the target so a partial write (process killed, full disk
     * mid-stream) cannot leave a truncated `routes.cache.php` that the
     * production bootstrap would then try to load.
     */
    private function writeFile(string $path, string $contents): void
    {
        AtomicFile::replace($path, $contents, 'compiled file');
    }
}
