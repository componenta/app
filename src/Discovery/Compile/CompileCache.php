<?php

declare(strict_types=1);

namespace Componenta\App\Discovery\Compile;

use Closure;
use Componenta\App\Cache\AtomicFile;
use Componenta\VarExport\Export;

/**
 * Disk-backed store for the dev-time compile output.
 *
 * Holds an arbitrary config delta - everything the cold boot decided to
 * precompute in one place: the discovery-target map consumed by
 * {@see \Componenta\App\Discovery\ListenerRestorer}, DI plans consumed by
 * `ParametersResolver`, per-listener maps produced by
 * {@see DiscoveryCompiler} (CQRS locator outputs, etc.). The loader
 * replays it verbatim into the config provider chain; the persister
 * just `var_export`s whatever the boot flow hands it.
 *
 * Freshness tracks the baseline file's mtime (typically the discovery
 * snapshot) - any `.php` change in watched directories bumps that and
 * stales everything here in one shot, as intended.
 *
 * Not used in production: the CLI `discovery:compile` bakes an
 * equivalent delta straight into `config.cache.php`.
 */
final readonly class CompileCache
{
    public function __construct(
        private string $cacheFile,
        private string $baselineFile,
    ) {}

    public function cacheFile(): string
    {
        return $this->cacheFile;
    }

    /**
     * @return array<string, mixed>|null Full config delta or null if stale / missing.
     */
    public function load(): ?array
    {
        return AtomicFile::readLocked($this->cacheFile, fn (): ?array => $this->loadUnlocked());
    }

    /**
     * @return array<string, mixed>|null Full config delta or null if stale / missing.
     */
    private function loadUnlocked(): ?array
    {
        if (!is_file($this->cacheFile) || !is_file($this->baselineFile)) {
            return null;
        }

        if (filemtime($this->cacheFile) < filemtime($this->baselineFile)) {
            return null;
        }

        /** @var mixed $loaded */
        $loaded = include $this->cacheFile;

        return is_array($loaded) ? $loaded : null;
    }

    /**
     * @param array<string, mixed> $delta Config keys (at any depth) to persist.
     */
    public function persist(array $delta): void
    {
        AtomicFile::writeLocked($this->cacheFile, function (Closure $replace) use ($delta): void {
            $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . Export::pretty($delta) . ";\n";

            $replace($contents, 'cache');
        });
    }
}
