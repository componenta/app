<?php

declare(strict_types=1);

namespace Componenta\App\Discovery\Artifact;

use Componenta\App\Cache\AtomicFile;
use Componenta\App\Cache\CacheLayout;
use JsonException;
use RuntimeException;
use Throwable;

final readonly class ArtifactRepository
{
    public const int VERSION = 1;

    public function __construct(private CacheLayout $cache)
    {
    }

    /**
     * @param list<string> $diagnostics
     * @return array<array-key, mixed>|null
     */
    public function read(string $section, array &$diagnostics): ?array
    {
        try {
            self::assertSectionName($section);
            $current = $this->decodeFile($this->cache->current);
            $generation = $current['generation'] ?? null;
            $manifestHash = $current['sha256'] ?? null;

            if (!is_string($generation)
                || preg_match('/^[a-f0-9]{64}$/D', $generation) !== 1
                || !is_string($manifestHash)
                || preg_match('/^[a-f0-9]{64}$/D', $manifestHash) !== 1
            ) {
                throw new RuntimeException('Current discovery pointer has an invalid shape.');
            }

            $manifestPath = $this->cache->manifest($generation);
            $manifestContents = $this->readFile($manifestPath);
            if (!hash_equals($manifestHash, hash('sha256', $manifestContents))) {
                throw new RuntimeException('Discovery manifest checksum mismatch.');
            }

            $manifest = $this->decode($manifestContents, $manifestPath);
            if (($manifest['format'] ?? null) !== 'componenta.discovery'
                || ($manifest['version'] ?? null) !== self::VERSION
                || ($manifest['generation'] ?? null) !== $generation
                || !is_array($manifest['sections'] ?? null)
            ) {
                throw new RuntimeException('Discovery manifest is incompatible.');
            }

            $specification = $manifest['sections'][$section] ?? null;
            if (!is_array($specification)) {
                throw new RuntimeException(sprintf('Discovery section "%s" is missing.', $section));
            }

            $file = $specification['file'] ?? null;
            $hash = $specification['sha256'] ?? null;
            if (!is_string($file)
                || basename($file) !== $file
                || !is_string($hash)
                || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
            ) {
                throw new RuntimeException(sprintf('Discovery section "%s" has an invalid manifest entry.', $section));
            }

            $path = $this->cache->generation($generation) . '/' . $file;
            $contents = $this->readFile($path);
            if (!hash_equals($hash, hash('sha256', $contents))) {
                throw new RuntimeException(sprintf('Discovery section "%s" checksum mismatch.', $section));
            }

            return $this->decode($contents, $path);
        } catch (Throwable $e) {
            $diagnostics[] = sprintf(
                'Discovery artifact section "%s" unavailable: %s',
                $section,
                $e->getMessage(),
            );

            return null;
        }
    }

    /**
     * @param array<string, array<array-key, mixed>> $sections
     */
    public function publish(array $sections): PublishedGeneration
    {
        ksort($sections, SORT_STRING);
        $encoded = [];
        $specifications = [];

        foreach ($sections as $name => $data) {
            self::assertSectionName($name);
            $contents = $this->encode($data);
            $filename = $name . '.json';
            $encoded[$filename] = $contents;
            $specifications[$name] = [
                'file' => $filename,
                'sha256' => hash('sha256', $contents),
            ];
        }

        $generation = hash('sha256', $this->encode($specifications));
        $manifestData = [
            'format' => 'componenta.discovery',
            'version' => self::VERSION,
            'generation' => $generation,
            'sections' => $specifications,
        ];
        $manifest = $this->encode($manifestData);
        $directory = $this->cache->generation($generation);

        if (is_dir($directory)) {
            $this->assertExistingGeneration($directory, $encoded, $manifest);
        } else {
            $this->writeGeneration($directory, $encoded, $manifest);
        }

        AtomicFile::replace($this->cache->current, $this->encode([
            'version' => self::VERSION,
            'generation' => $generation,
            'sha256' => hash('sha256', $manifest),
        ]), 'discovery pointer');

        return new PublishedGeneration(
            generation: $generation,
            directory: $directory,
            manifest: $directory . '/manifest.json',
        );
    }

    /** @return array<array-key, mixed> */
    private function decodeFile(string $path): array
    {
        return $this->decode($this->readFile($path), $path);
    }

    /** @return array<array-key, mixed> */
    private function decode(string $contents, string $path): array
    {
        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Invalid JSON in "%s".', $path), previous: $e);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Artifact "%s" must contain a JSON array or object.', $path));
        }

        return $decoded;
    }

    /** @param array<array-key, mixed> $data */
    private function encode(array $data): string
    {
        try {
            return json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ) . "\n";
        } catch (JsonException $e) {
            throw new RuntimeException('Discovery artifact contains non-data values.', previous: $e);
        }
    }

    private function readFile(string $path): string
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Artifact "%s" does not exist.', $path));
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read "%s".', $path));
        }

        return $contents;
    }

    /**
     * @param array<string, string> $files
     */
    private function writeGeneration(string $directory, array $files, string $manifest): void
    {
        $parent = dirname($directory);
        if (!is_dir($parent) && !mkdir($parent, 0o755, recursive: true) && !is_dir($parent)) {
            throw new RuntimeException(sprintf('Unable to create discovery generations directory "%s".', $parent));
        }

        $temporary = $directory . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (!mkdir($temporary, 0o755)) {
            throw new RuntimeException(sprintf('Unable to create temporary discovery generation "%s".', $temporary));
        }

        try {
            foreach ($files as $filename => $contents) {
                if (file_put_contents($temporary . '/' . $filename, $contents) === false) {
                    throw new RuntimeException(sprintf('Unable to write discovery artifact "%s".', $filename));
                }
            }
            if (file_put_contents($temporary . '/manifest.json', $manifest) === false) {
                throw new RuntimeException('Unable to write discovery manifest.');
            }
            if (!rename($temporary, $directory)) {
                throw new RuntimeException('Unable to publish immutable discovery generation.');
            }
        } finally {
            if (is_dir($temporary)) {
                foreach (glob($temporary . '/*') ?: [] as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                rmdir($temporary);
            }
        }
    }

    /**
     * @param array<string, string> $files
     */
    private function assertExistingGeneration(string $directory, array $files, string $manifest): void
    {
        foreach ([...$files, 'manifest.json' => $manifest] as $filename => $contents) {
            $path = $directory . '/' . $filename;
            if (!is_file($path) || !hash_equals(hash('sha256', $contents), hash_file('sha256', $path) ?: '')) {
                throw new RuntimeException(sprintf(
                    'Immutable discovery generation "%s" is incomplete or modified.',
                    basename($directory),
                ));
            }
        }
    }

    private static function assertSectionName(string $section): void
    {
        if (preg_match('/^[a-z][a-z0-9._-]*$/D', $section) !== 1) {
            throw new RuntimeException(sprintf('Invalid discovery section name "%s".', $section));
        }
    }
}
