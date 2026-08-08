<?php

declare(strict_types=1);

namespace Componenta\App\Discovery;

use Componenta\Stdlib\PathResolverInterface;
use Componenta\ClassFinder\Attribute\DevOnly;
use Componenta\ClassFinder\ClassListenerInterface;
use Componenta\ClassFinder\ClassListenerProviderInterface;
use Componenta\ClassFinder\FinalizableListenerInterface;
use Componenta\Config\Config;
use Componenta\Tokenizer\ClassInfo;
use ReflectionClass;


/**
 * Replays a previously compiled discovery cache into the configured
 * listeners at runtime - production's counterpart to {@see Discovery}.
 *
 * Reads the cache under {@see CACHE_KEY} from the application {@see Config}.
 * The key is populated by one of two writers:
 *
 *  - in production, by the CLI `app:build` command (via
 *    {@see ListenerCompiler}), baked straight into `config.cache.php`;
 *  - in development, by `ClassDiscoveryBootloader`'s cold path persisting
 *    the same shape into the dev compile cache, which `config/config.php`
 *    spreads back into the config on subsequent warm requests.
 *
 * When the key is absent or empty, `restore()` is a no-op.
 *
 * The restore feeds every eligible listener either its declared class
 * subset (if the listener has a `#[ListenTo]` target recorded in the
 * cache) or the full class list. `handle()` runs once per class and
 * does access `ClassInfo::$reflector` - which lazily triggers
 * `ReflectionClass`; runtime therefore pays a reflection cost per
 * `handle()` call, just a much smaller one than the full notifier fanout
 * would. Missing-class autoload errors are delayed until a listener
 * actually touches the reflector, not at snapshot load.
 */
final class ListenerRestorer
{
    public const int CACHE_VERSION = 1;

    /**
     * Config key under which {@see ListenerCompiler} stores the restoreable
     * cache. Shared with the compiler so both ends agree without tying them
     * together.
     */
    public const string CACHE_KEY = 'Componenta\App\Discovery::cache';

    /**
     * Config key pointing to a sidecar file containing the restoreable cache.
     */
    public const string CACHE_FILE_KEY = 'Componenta\App\Discovery::cache_file';
    /** @var array<class-string, true> */
    private static array $devOnly = [];

    /** @var array<class-string, true> */
    private static array $regular = [];


    public function __construct(
        private readonly ClassListenerProviderInterface $listenerProvider,
        private readonly Config $config,
        private readonly PathResolverInterface $paths,
    ) {}

    /**
     * @param bool $includeDevOnly When true, listeners marked `#[DevOnly]`
     *                             are restored as well. Prod callers leave
     *                             the default (false) so dev-only scanners
     *                             stay inert in production. Dev callers
     *                             (when replaying a cached dev snapshot to
     *                             avoid a full fanout) set true.
     */
    public function restore(bool $includeDevOnly = false): void
    {
        $cache = $this->cache();

        if ($cache === []) {
            return;
        }

        $allClasses = $cache['classes'] ?? [];
        $targets    = $cache['targets'] ?? [];
        $emptyTargets = array_flip($cache['empty_targets'] ?? []);

        foreach ($this->listenerProvider->getClassListeners() as $listener) {
            if (!$includeDevOnly && $this->isDevOnly($listener)) {
                continue;
            }

            $key = $listener::class;

            if (isset($targets[$key])) {
                $classNames = array_map(
                    static fn (int $i): string => $allClasses[$i],
                    $targets[$key],
                );
            } elseif (isset($emptyTargets[$key])) {
                $classNames = [];
            } else {
                $classNames = $allClasses;
            }

            foreach ($classNames as $className) {
                $listener->handle(new ClassInfo($className));
            }

            if ($listener instanceof FinalizableListenerInterface) {
                $listener->finalize();
            }
        }
    }

    public function hasCache(): bool
    {
        return $this->cache() !== [];
    }

    /**
     * @return array{classes?: list<non-empty-string>, targets?: array<non-empty-string, list<int>>, empty_targets?: list<non-empty-string>}
     */
    private function cache(): array
    {
        $inline = $this->config->get(self::CACHE_KEY, []);
        $inline = self::normalizeCache($inline);

        if ($inline !== []) {
            return $inline;
        }

        $file = $this->config->get(self::CACHE_FILE_KEY, null);

        if (!is_string($file) || $file === '') {
            return [];
        }

        $path = $this->paths->resolve($file);

        if (!is_file($path)) {
            return [];
        }

        $payload = require $path;

        if (!is_array($payload) || ($payload['version'] ?? null) !== self::CACHE_VERSION) {
            return [];
        }

        $cache = $payload['cache'] ?? [];

        return self::normalizeCache($cache);
    }


    /**
     * Per-class memoisation of the `#[DevOnly]` check. Listener classes
     * are singletons for the lifetime of the container, so the answer is
     * stable and worth caching - each lookup would otherwise build a
     * fresh `ReflectionClass` plus an attribute scan per restore call.
     *
     * Two class-level sets keep the cached value strictly typed as bool.
     */
    private function isDevOnly(ClassListenerInterface $listener): bool
    {
        $class = $listener::class;

        if (isset(self::$devOnly[$class])) {
            return true;
        }

        if (isset(self::$regular[$class])) {
            return false;
        }

        $isDevOnly = new ReflectionClass($class)->getAttributes(DevOnly::class) !== [];

        if ($isDevOnly) {
            self::$devOnly[$class] = true;
        } else {
            self::$regular[$class] = true;
        }

        return $isDevOnly;
    }

    /**
     * @return array{
     *     classes?: list<non-empty-string>,
     *     targets?: array<non-empty-string, list<int>>,
     *     empty_targets?: list<non-empty-string>
     * }
     */
    private static function normalizeCache(mixed $cache): array
    {
        if (!is_array($cache)) {
            return [];
        }

        foreach (array_keys($cache) as $key) {
            if (!is_string($key)
                || !in_array($key, ['classes', 'targets', 'empty_targets'], true)
            ) {
                return [];
            }
        }

        $classes = $cache['classes'] ?? [];

        if (!is_array($classes) || !array_is_list($classes)) {
            return [];
        }

        $normalizedClasses = [];

        foreach ($classes as $class) {
            if (!is_string($class) || trim($class) === '') {
                return [];
            }

            $normalizedClasses[] = $class;
        }

        $targets = $cache['targets'] ?? [];

        if (!is_array($targets)) {
            return [];
        }

        $normalizedTargets = [];
        /** @var array<non-empty-string, true> $normalizedEmptyTargetSet */
        $normalizedEmptyTargetSet = [];

        foreach ($targets as $listener => $indices) {
            if (!is_string($listener)
                || trim($listener) === ''
                || !is_array($indices)
                || !array_is_list($indices)
            ) {
                return [];
            }

            $normalizedIndices = [];

            foreach ($indices as $index) {
                if (!is_int($index) || $index < 0 || !array_key_exists($index, $normalizedClasses)) {
                    return [];
                }

                $normalizedIndices[] = $index;
            }

            if ($normalizedIndices !== []) {
                $normalizedTargets[$listener] = $normalizedIndices;
            } else {
                // Older caches encoded a listener with no matching classes as
                // an empty target map. Keep accepting that representation,
                // but normalize it to the compact v1 schema in memory.
                $normalizedEmptyTargetSet[$listener] = true;
            }
        }

        $emptyTargets = $cache['empty_targets'] ?? [];

        if (!is_array($emptyTargets) || !array_is_list($emptyTargets)) {
            return [];
        }

        foreach ($emptyTargets as $listener) {
            if (!is_string($listener) || trim($listener) === '') {
                return [];
            }

            $normalizedEmptyTargetSet[$listener] = true;
        }

        $normalizedEmptyTargets = array_keys($normalizedEmptyTargetSet);

        $normalized = [];

        if ($normalizedClasses !== []) {
            $normalized['classes'] = $normalizedClasses;
        }
        if ($normalizedTargets !== []) {
            $normalized['targets'] = $normalizedTargets;
        }
        if ($normalizedEmptyTargets !== []) {
            $normalized['empty_targets'] = $normalizedEmptyTargets;
        }

        return $normalized;
    }
}
