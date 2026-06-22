<?php

declare(strict_types=1);

namespace Componenta\App\Cache;

use Closure;
use RuntimeException;

final class AtomicFile
{
    private function __construct() {}

    /**
     * Replace a file under the companion lock.
     *
     * Readers that need a consistent view must use {@see readLocked()}.
     * The class does not guarantee atomic visibility for readers that
     * include or read the target path directly without taking the lock.
     */
    public static function replace(string $path, string $contents, string $label = 'file'): void
    {
        self::writeLocked($path, static function (Closure $replace) use ($contents, $label): void {
            $replace($contents, $label);
        });
    }

    public static function readLocked(string $path, Closure $callback): mixed
    {
        return self::withLock($path, LOCK_SH, $callback);
    }

    public static function writeLocked(string $path, Closure $callback): mixed
    {
        return self::withLock(
            $path,
            LOCK_EX,
            static fn (): mixed => $callback(
                static function (string $contents, string $label = 'file') use ($path): void {
                    self::replaceUnlocked($path, $contents, $label);
                },
            ),
        );
    }

    private static function replaceUnlocked(string $path, string $contents, string $label): void
    {
        self::ensureDirectory(dirname($path), "{$label} directory");

        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($tmp, $contents) === false) {
            throw new RuntimeException("Unable to write {$label}: {$path}");
        }

        if (self::moveIntoPlace($tmp, $path)) {
            self::invalidateOpcache($path);

            return;
        }

        @unlink($tmp);

        throw new RuntimeException("Unable to finalise {$label}: {$path}");
    }

    private static function withLock(string $path, int $operation, Closure $callback): mixed
    {
        self::ensureDirectory(dirname($path), 'cache lock directory');

        $lockFile = $path . '.lock';
        $lock = fopen($lockFile, 'c');

        if ($lock === false) {
            throw new RuntimeException("Unable to open cache lock: {$lockFile}");
        }

        try {
            if (!flock($lock, $operation)) {
                throw new RuntimeException("Unable to lock cache: {$lockFile}");
            }

            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function moveIntoPlace(string $tmp, string $path): bool
    {
        return @rename($tmp, $path);
    }

    private static function ensureDirectory(string $dir, string $label): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0o755, recursive: true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create {$label}: {$dir}");
        }
    }

    private static function invalidateOpcache(string $path): void
    {
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }
    }
}
