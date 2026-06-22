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
 *  - in production, by the CLI `discovery:compile` command (via
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
final readonly class ListenerRestorer
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

    public function __construct(
        private ClassListenerProviderInterface $listenerProvider,
        private Config                         $config,
        private PathResolverInterface          $paths,
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
        /** @var array{classes?: list<class-string>, targets?: array<class-string, list<int>>} $cache */
        $cache = $this->cache();

        if ($cache === []) {
            return;
        }

        $allClasses = $cache['classes'] ?? [];
        $targets    = $cache['targets'] ?? [];

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
     * @return array{classes?: list<class-string>, targets?: array<class-string, list<int>>}
     */
    private function cache(): array
    {
        $inline = $this->config->get(self::CACHE_KEY, []);

        if (is_array($inline) && $inline !== []) {
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

        return is_array($cache) ? $cache : [];
    }

    /**
     * Per-class memoisation of the `#[DevOnly]` check. Listener classes
     * are singletons for the lifetime of the container, so the answer is
     * stable and worth caching - each lookup would otherwise build a
     * fresh `ReflectionClass` plus an attribute scan per restore call.
     *
     * A function-scoped `static` is used (rather than a class property)
     * because this class is `readonly`, which forbids writable instance
     * or statically-defaulted properties.
     */
    private function isDevOnly(ClassListenerInterface $listener): bool
    {
        static $cache = [];
        $class = $listener::class;

        return $cache[$class] ??= new ReflectionClass($class)->getAttributes(DevOnly::class) !== [];
    }

}
