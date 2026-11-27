<?php

declare(strict_types=1);

namespace Componenta\App\Build;

use Componenta\App\Internal\AtomicFileWriter;
use Componenta\App\Internal\BuildArtifact;
use Componenta\VarExport\ArrayExporter;
use Componenta\VarExport\EnumExporter;
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
                try {
                    opcache_invalidate($file, true);
                } catch (ErrorException) {
                    // A restricted OPcache API does not make a completed unlink fail.
                }
            }
        } finally {
            restore_error_handler();
            clearstatcache(true, $file);
        }
    }

    /**
     * Write a map of scalars, arrays and enum cases, optionally tied to the current discovery build generation.
     */
    public static function write(string $file, array $map, ?string $generation = null): void
    {
        if ($generation !== null) {
            $map = BuildArtifact::wrap($generation, $map);
        }
        $expression = (new VarExport(new ScalarExporter(), new ArrayExporter(), new EnumExporter()))->export($map);
        AtomicFileWriter::write($file, "<?php\ndeclare(strict_types=1);\nreturn " . $expression . ";\n");
    }

    /**
     * Read a trusted PHP map. When a generation is supplied, accept only a
     * matching versioned artifact and return its payload.
     */
    public static function read(string $file, ?string $generation = null): ?array
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
            $level = ob_get_level();
            if (!ob_start()) {
                return null;
            }
            try {
                $map = (static fn (string $file): mixed => require $file)($file);
                // A malformed file may emit text rather than return a map.
                // Do not let this corrupt the application response.
                if (ob_get_level() !== $level + 1 || ob_get_contents() !== '' || !is_array($map)) {
                    return null;
                }
                if ($generation === null) {
                    return $map;
                }

                $artifact = BuildArtifact::unwrap($map);
                return $artifact !== null && $artifact['generation'] === $generation
                    ? $artifact['payload']
                    : null;
            } finally {
                while (ob_get_level() > $level) {
                    if (!ob_end_clean()) {
                        break;
                    }
                }
            }
        } catch (Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }
    }
}
