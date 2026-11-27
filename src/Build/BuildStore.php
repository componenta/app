<?php

declare(strict_types=1);

namespace Componenta\App\Build;

use Componenta\App\Internal\BuildArtifact;
use Componenta\App\Internal\AtomicFileWriter;
use ErrorException;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Throwable;

/** Publishes a complete generation with one atomic current-pointer replacement. */
final class BuildStore
{
    private const int VERSION = 2;
    // A leading underscore is outside the artifact-ID namespace.
    private const string MANIFEST_FILE = '_manifest.php';
    private readonly string $directory;
    private readonly object $owner;
    /** @var list<string> */
    private readonly array $requiredArtifacts;

    /**
     * Relative directories are resolved once, before any build context or reader is created.
     * @param list<string> $requiredArtifacts Explicit deployment policy; independent of APP_ENV.
     */
    public function __construct(
        string $directory,
        private readonly ?string $identity = null,
        array $requiredArtifacts = [],
    ) {
        if ($directory === '' || ($identity !== null && trim($identity) === '')) {
            throw new InvalidArgumentException('Build directory and supplied identity must be non-empty.');
        }
        if (!array_is_list($requiredArtifacts)) {
            throw new InvalidArgumentException('Required artifacts must be a list of artifact IDs.');
        }
        $policy = [];
        foreach ($requiredArtifacts as $id) {
            if (!is_string($id) || !BuildContext::validId($id)) {
                throw new InvalidArgumentException('Required artifacts must contain valid artifact IDs.');
            }
            // Copy scalar values instead of retaining caller-owned reference slots.
            $policy[$id] = $id;
        }
        $this->requiredArtifacts = array_values($policy);
        $this->directory = self::anchorDirectory($directory);
        $this->owner = new stdClass();
    }

    public function begin(): BuildContext
    {
        $generation = BuildArtifact::newGeneration();
        return new BuildContext($this->generationDirectory($generation), $generation, $this->owner);
    }

    public function publish(BuildContext $build): PreparedBuild
    {
        $artifacts = $build->seal($this->owner);
        $directory = $this->generationDirectory($build->generation);
        $prepared = new PreparedBuild($directory, $build->generation, $artifacts, $this->requiredArtifacts);
        $this->requireArtifacts($prepared, $artifacts);

        PhpMapFile::write($directory . '/' . self::MANIFEST_FILE, [
            'version' => self::VERSION,
            'generation' => $build->generation,
            'identity' => $this->identity,
            'artifacts' => $artifacts,
        ]);
        // This is the only operation that makes a generation visible to new bootstraps.
        AtomicFileWriter::write($this->directory . '/current.json', json_encode([
            'version' => self::VERSION,
            'generation' => $build->generation,
        ], JSON_THROW_ON_ERROR) . "\n");

        return $prepared;
    }

    public function load(): ?PreparedBuild
    {
        $current = $this->readCurrent();
        $generation = $current['generation'] ?? null;
        if (($current['version'] ?? null) !== self::VERSION
            || !is_string($generation)
            || preg_match('/\A[0-9a-f]{32}\z/', $generation) !== 1
        ) {
            return $this->unavailable();
        }

        $directory = $this->generationDirectory($generation);
        $manifest = PhpMapFile::read($directory . '/' . self::MANIFEST_FILE);
        if (($manifest['version'] ?? null) !== self::VERSION
            || ($manifest['generation'] ?? null) !== $generation
            || !array_key_exists('identity', $manifest ?? [])
            || (!is_string($manifest['identity']) && $manifest['identity'] !== null)
            || $manifest['identity'] !== $this->identity
            || !self::validArtifacts($manifest['artifacts'] ?? null)
        ) {
            return $this->unavailable();
        }

        $prepared = new PreparedBuild($directory, $generation, $manifest['artifacts'], $this->requiredArtifacts);
        $this->requireArtifacts($prepared, $manifest['artifacts']);

        return $prepared;
    }

    /** Close a failed run's write capability; retain its files for offline cleanup. */
    public function abandon(BuildContext $build): void
    {
        $build->seal($this->owner, abandon: true);
    }

    /** Unpublish only. Existing PreparedBuild instances retain their immutable generation. */
    public function clean(): void
    {
        PhpMapFile::remove($this->directory . '/current.json');
    }

    /** Mutable selection is data, so another process's publish never depends on OPcache freshness. */
    private function readCurrent(): ?array
    {
        $file = $this->directory . '/current.json';
        clearstatcache(true, $file);
        if (is_link($file) || !is_file($file) || !is_readable($file)) {
            return null;
        }
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            if (DIRECTORY_SEPARATOR === '/') {
                $permissions = fileperms($file);
                if ($permissions === false || ($permissions & 0o022) !== 0) {
                    return null;
                }
            }
            $contents = file_get_contents($file);
            if ($contents === false) {
                return null;
            }
            $pointer = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);

            return is_array($pointer) ? $pointer : null;
        } catch (Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }
    }

    private function unavailable(): null
    {
        if ($this->requiredArtifacts !== []) {
            throw new RuntimeException('A published build matching the deployment identity is required.');
        }
        return null;
    }

    private function requireArtifacts(PreparedBuild $prepared, array $artifacts): void
    {
        foreach ($this->requiredArtifacts as $id) {
            $artifact = $artifacts[$id] ?? null;
            if (($artifact['state'] ?? null) !== 'written'
                || $prepared->read($id, $artifact['schema']) === null
            ) {
                throw new RuntimeException(sprintf('Required build artifact "%s" is missing, skipped or unreadable.', $id));
            }
        }
    }

    private function generationDirectory(string $generation): string
    {
        return $this->directory . '/generations/' . $generation;
    }

    private static function anchorDirectory(string $directory): string
    {
        if ((DIRECTORY_SEPARATOR === '/' && $directory[0] === '/')
            || (DIRECTORY_SEPARATOR === '\\' && preg_match('~\A(?:[A-Za-z]:[\\\\/]|[\\\\/]{2})~', $directory) === 1)
        ) {
            return $directory;
        }

        // Keep native filesystem semantics: "symlink/.." must not be collapsed.
        $relative = $directory;
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('~\A([A-Za-z]:)~', $directory, $drive) === 1) {
            $base = realpath($drive[1] . '.');
            $relative = substr($directory, 2);
        } elseif (DIRECTORY_SEPARATOR === '\\' && str_contains('/\\', $directory[0])) {
            $base = realpath(DIRECTORY_SEPARATOR);
            $relative = ltrim($directory, '/\\');
        } else {
            $base = getcwd();
        }
        if ($base === false) {
            throw new RuntimeException('Cannot resolve the application build directory.');
        }

        return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative;
    }

    private static function validArtifacts(mixed $artifacts): bool
    {
        if (!is_array($artifacts)) {
            return false;
        }
        foreach ($artifacts as $id => $artifact) {
            if (!is_string($id) || !BuildContext::validId($id) || !is_array($artifact)) {
                return false;
            }
            if (($artifact['state'] ?? null) === 'written') {
                if (!is_int($artifact['schema'] ?? null) || $artifact['schema'] < 1) {
                    return false;
                }
            } elseif (($artifact['state'] ?? null) === 'skipped') {
                if (!is_string($artifact['reason'] ?? null) || trim($artifact['reason']) === '') {
                    return false;
                }
            } else {
                return false;
            }
        }
        return true;
    }
}
