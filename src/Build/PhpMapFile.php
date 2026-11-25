<?php

declare(strict_types=1);

namespace Componenta\App\Build;

use Componenta\App\Internal\AtomicFileWriter;
use Componenta\VarExport\ArrayExporter;
use Componenta\VarExport\ScalarExporter;
use Componenta\VarExport\VarExport;
use ErrorException;
use RuntimeException;
use Throwable;

/** Technical file I/O; each consumer owns its path and validates its map schema. */
final class PhpMapFile
{
    public static function remove(string $file): void
    {
        clearstatcache(true, $file);
        if (!file_exists($file) && !is_link($file)) {
            return;
        }
        if (is_dir($file) && !is_link($file)) {
            throw new RuntimeException(sprintf('Cannot remove map "%s": expected a file, got a directory.', $file));
        }
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            try {
                if (!unlink($file)) {
                    throw new RuntimeException(sprintf('Cannot remove map "%s".', $file));
                }
            } catch (ErrorException $error) {
                clearstatcache(true, $file);
                if (file_exists($file) || is_link($file)) {
                    throw $error;
                }
                // A concurrent cleanup already removed the owned artifact.
            }
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
        } finally {
            restore_error_handler();
            clearstatcache(true, $file);
        }
    }

    public static function write(string $file, array $map): void
    {
        $expression = (new VarExport(new ScalarExporter(), new ArrayExporter()))->export($map);
        AtomicFileWriter::write($file, "<?php\ndeclare(strict_types=1);\nreturn " . $expression . ";\n");
    }

    public static function read(string $file): ?array
    {
        clearstatcache(true, $file);
        if (is_link($file) || !is_file($file) || !is_readable($file)) {
            return null;
        }
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            // Executable PHP maps are trusted deployment artifacts, never user data.
            // Refuse files writable by other POSIX users even when otherwise readable.
            if (DIRECTORY_SEPARATOR === '/') {
                $permissions = fileperms($file);
                if ($permissions === false || ($permissions & 0o022) !== 0) {
                    return null;
                }
            }
            $map = (static fn (string $file): mixed => require $file)($file);
            return is_array($map) ? $map : null;
        } catch (Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }
    }
}
