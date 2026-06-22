<?php

declare(strict_types=1);

namespace Componenta\App\Discovery;

use Closure;
use Componenta\App\Cache\AtomicFile;
use Componenta\ClassFinder\ClassFinder;
use Componenta\ClassFinder\ClassIterator;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Tokenizer\ClassInfo;
use Componenta\Tokenizer\DeclarationType;
use Componenta\VarExport\Export;
use FilesystemIterator;
use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Dev-time class discovery with transparent filesystem-mtime cache.
 *
 * A single call to {@see discover()} returns a {@see ClassIteratorInterface}.
 * Internally: if a snapshot file exists and every `.php` in `$dirs` (plus
 * the directories themselves, to catch deletions) is older than the
 * snapshot, classes are replayed from it (~5-10 ms); otherwise a fresh
 * scan runs and the snapshot is rewritten before returning.
 *
 * The cache location is provided by the application bootstrap.
 *
 * Production has its own compile-time snapshot (`config.cache.php` via
 * `discovery:compile`); Discovery is not used there.
 */
final readonly class Discovery
{
    public function __construct(
        private string $cacheFile,
    ) {}

    /**
     * @param list<string> $dirs    Absolute paths to scan.
     * @param list<string> $exclude Patterns forwarded to the underlying `Finder::exclude()`.
     */
    public function discover(array $dirs, array $exclude = []): ClassIteratorInterface
    {
        $cacheFile = $this->cacheFile();

        $cached = AtomicFile::readLocked(
            $cacheFile,
            fn (): ?ClassIteratorInterface => $this->restoreFreshCache($cacheFile, $dirs),
        );

        if ($cached !== null) {
            return $cached;
        }

        return AtomicFile::writeLocked(
            $cacheFile,
            function (Closure $replace) use ($dirs, $exclude, $cacheFile): ClassIteratorInterface {
                $cached = $this->restoreFreshCache($cacheFile, $dirs);

                return $cached ?? $this->scanAndPersist($dirs, $exclude, $cacheFile, $replace);
            }
        );
    }

    /**
     * @param list<string> $dirs
     */
    private function restoreFreshCache(string $cacheFile, array $dirs): ?ClassIteratorInterface
    {
        $cacheMtime = is_file($cacheFile) ? filemtime($cacheFile) : 0;

        if ($cacheMtime <= 0 || !$this->isFresh($cacheMtime, $dirs)) {
            return null;
        }

        try {
            return $this->restore($cacheFile);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Returns true if no `.php` file under any watched directory has been
     * modified, added, or removed since `$cacheMtime`.
     *
     * File-level edits / additions are caught by comparing each file's
     * mtime. Deletions are caught by tracking each directory's mtime
     * too: most filesystems bump a directory's mtime when a child entry
     * disappears, which closes the gap where a deleted class would
     * otherwise persist in the snapshot and blow up later via
     * `ReflectionClass` on a missing file.
     *
     * Short-circuits on the first newer target.
     *
     * @param list<string> $dirs
     */
    private function isFresh(int $cacheMtime, array $dirs): bool
    {
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                // Directory vanished since the cache was written - invalidate.
                return false;
            }

            // Top-level dir mtime - single stat, catches first-level add/remove.
            if (filemtime($dir) > $cacheMtime) {
                return false;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $dir,
                    FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS,
                ),
            );

            foreach ($iterator as $entry) {
                // Subdirectory mtime - catches add/remove inside nested folders.
                if ($entry->isDir()) {
                    if ($entry->getMTime() > $cacheMtime) {
                        return false;
                    }

                    continue;
                }

                if (!$entry->isFile() || $entry->getExtension() !== 'php') {
                    continue;
                }

                if ($entry->getMTime() > $cacheMtime) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param list<string> $dirs
     * @param list<string> $exclude
     */
    private function scanAndPersist(array $dirs, array $exclude, string $cacheFile, Closure $replace): ClassIteratorInterface
    {
        $classes = new ClassFinder()->find($dirs, $exclude);
        $snapshot = [];

        // Force full traversal so the underlying ReplayableIterator caches
        // every ClassInfo up front; subsequent consumers iterate the in-memory
        // cache without re-tokenising a single file. Collect the serialisable
        // form at the same time.
        foreach ($classes as $filename => $classInfo) {
            $snapshot[] = [
                'file'       => $filename,
                'fqcn'       => $classInfo->fullyQualifiedName,
                'type'       => $classInfo->type->value,
                'isAbstract' => $classInfo->isAbstract,
                'isFinal'    => $classInfo->isFinal,
                'isReadonly' => $classInfo->isReadonly,
            ];
        }

        $this->persist($snapshot, $replace);

        return $classes;
    }

    /**
     * @param list<array{file: string, fqcn: class-string, type: string, isAbstract: bool, isFinal: bool, isReadonly: bool}> $snapshot
     */
    private function persist(array $snapshot, Closure $replace): void
    {
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . Export::pretty($snapshot) . ";\n";
        $replace($contents, 'discovery cache');
    }

    private function restore(string $cacheFile): ClassIteratorInterface
    {
        /** @var list<array{file: string, fqcn: class-string, type: string, isAbstract: bool, isFinal: bool, isReadonly: bool}> $snapshot */
        $snapshot = include $cacheFile;

        if (!is_array($snapshot)) {
            throw new RuntimeException("Discovery cache is malformed: {$cacheFile}");
        }

        return new ClassIterator($this->replay($snapshot));
    }

    /**
     * @param list<array{file: string, fqcn: class-string, type: string, isAbstract: bool, isFinal: bool, isReadonly: bool}> $snapshot
     *
     * @return Generator<string, ClassInfo>
     */
    private function replay(array $snapshot): Generator
    {
        foreach ($snapshot as $row) {
            yield $row['file'] => new ClassInfo(
                fullyQualifiedName: $row['fqcn'],
                type:               DeclarationType::from($row['type']),
                isAbstract:         $row['isAbstract'],
                isFinal:            $row['isFinal'],
                isReadonly:         $row['isReadonly'],
            );
        }
    }

    private function cacheFile(): string
    {
        return $this->cacheFile;
    }
}
