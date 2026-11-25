<?php

declare(strict_types=1);

namespace Componenta\App\Internal;

use ErrorException;
use RuntimeException;
use Throwable;

/** @internal Used by individual builders to publish their own files. */
final class AtomicFileWriter
{
    public static function write(string $file, string $content): void
    {
        $directory = dirname($file);
        $temporary = null;
        $stream = null;
        $failure = null;
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            self::ensureDirectory($directory);
            $temporary = $directory . '/.' . basename($file) . '.' . bin2hex(random_bytes(12)) . '.tmp';
            $stream = fopen($temporary, 'xb');
            if ($stream === false) {
                throw new RuntimeException(sprintf('Cannot open build file "%s".', $file));
            }
            if (DIRECTORY_SEPARATOR === '/') {
                $stat = fstat($stream);
                if ($stat === false || !chmod($temporary, ($stat['mode'] & 0o777) & ~0o022)) {
                    throw new RuntimeException(sprintf('Cannot secure build file "%s".', $file));
                }
            }
            if (fwrite($stream, $content) !== strlen($content) || !fflush($stream)) {
                throw new RuntimeException(sprintf('Cannot write complete build file "%s".', $file));
            }
            if (!fclose($stream)) {
                throw new RuntimeException(sprintf('Cannot close build file "%s".', $file));
            }
            $stream = null;
            if (!rename($temporary, $file)) {
                throw new RuntimeException(sprintf('Cannot publish build file "%s".', $file));
            }
            $temporary = null;
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
        } catch (Throwable $error) {
            $failure = $error;
            throw $error;
        } finally {
            try {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                if ($temporary !== null && is_file($temporary)) {
                    unlink($temporary);
                }
            } catch (Throwable $cleanupError) {
                // Preserve the failure that prevented publication.
                if ($failure === null) {
                    throw $cleanupError;
                }
            } finally {
                restore_error_handler();
            }
        }
    }

    private static function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        try {
            if (mkdir($directory, 0o755, true)) {
                return;
            }
        } catch (ErrorException $error) {
            // Another builder may have won the mkdir race.
            clearstatcache(true, $directory);
            if (!is_dir($directory)) {
                throw $error;
            }
            return;
        }

        clearstatcache(true, $directory);
        if (!is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create build directory "%s".', $directory));
        }
    }
}
