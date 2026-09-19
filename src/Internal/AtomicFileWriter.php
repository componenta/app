<?php

declare(strict_types=1);

namespace Componenta\App\Internal;

use ErrorException;
use RuntimeException;

/** @internal Used by individual builders to publish their own files. */
final class AtomicFileWriter
{
    public static function write(string $file, string $content): void
    {
        $directory = dirname($file);
        $temporary = null;
        $stream = null;
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('Cannot create build directory "%s".', $directory));
            }
            $temporary = $directory . '/.' . basename($file) . '.' . bin2hex(random_bytes(12)) . '.tmp';
            $stream = fopen($temporary, 'xb');
            if ($stream === false || fwrite($stream, $content) !== strlen($content) || !fflush($stream)) {
                throw new RuntimeException(sprintf('Cannot write complete build file "%s".', $file));
            }
            fclose($stream);
            $stream = null;
            if (!rename($temporary, $file)) {
                throw new RuntimeException(sprintf('Cannot publish build file "%s".', $file));
            }
            $temporary = null;
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
        } finally {
            try {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                if ($temporary !== null && is_file($temporary)) {
                    unlink($temporary);
                }
            } finally {
                restore_error_handler();
            }
        }
    }
}
