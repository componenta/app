<?php

declare(strict_types=1);

namespace Componenta\App\Config;

use Closure;
use Componenta\App\Cache\AtomicFile;
use Componenta\VarExport\Export;

/**
 * Caches the merged output of an attribute-scanning config provider to disk.
 *
 * The hot cost of {@see AttributeConfigProvider} in dev is the 500+ reflection
 * calls it makes to filter classes by a single attribute; its *output*, on
 * the other hand, is a small, mostly-static array. We persist the output and
 * replay it as long as the upstream discovery cache hasn't been rebuilt.
 *
 * Invalidation piggy-backs on {@see \Componenta\App\Discovery\Discovery}: whenever
 * discovery rewrites its snapshot (because a watched `.php` file changed),
 * the baseline file's mtime moves forward and this cache is treated as stale.
 * No separate filesystem walk is performed here.
 */
final readonly class CachedAttributeConfigProvider
{
    /**
     * Typed as {@see Closure} so misuse is caught at construction rather
     * than on the first cold call. Invokable providers are adapted with
     * PHP's first-class callable syntax: `$provider(...)`.
     *
     * @param Closure(): array $inner        Original provider whose result we cache.
     * @param string           $cacheFile    Absolute path to the cached output.
     * @param string           $baselineFile File whose mtime bounds the cache's validity.
     *                                        Typically the discovery snapshot.
     */
    public function __construct(
        private Closure $inner,
        private string  $cacheFile,
        private string  $baselineFile,
    ) {}

    public function __invoke(): array
    {
        $cached = $this->loadFreshCache();

        if ($cached !== null) {
            return $cached;
        }

        return $this->rebuildCache();
    }

    private function loadFreshCache(): ?array
    {
        return AtomicFile::readLocked($this->cacheFile, fn (): ?array => $this->loadFreshCacheWithoutLock());
    }

    private function loadFreshCacheWithoutLock(): ?array
    {
        $cacheMtime = is_file($this->cacheFile) ? filemtime($this->cacheFile) : 0;
        $baselineMtime = is_file($this->baselineFile) ? filemtime($this->baselineFile) : 0;

        if ($cacheMtime <= 0 || $baselineMtime <= 0 || $cacheMtime < $baselineMtime) {
            return null;
        }

        /** @var mixed $cached */
        $cached = include $this->cacheFile;

        return is_array($cached) ? $cached : null;
    }

    private function rebuildCache(): array
    {
        return AtomicFile::writeLocked($this->cacheFile, function (Closure $replace): array {
            $cached = $this->loadFreshCacheWithoutLock();

            if ($cached !== null) {
                return $cached;
            }

            $result = ($this->inner)();
            $this->persist($result, $replace);

            return $result;
        });
    }

    private function persist(array $result, Closure $replace): void
    {
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . Export::pretty($result) . ";\n";
        $replace($contents, 'cache');
    }
}
