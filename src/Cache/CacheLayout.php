<?php

declare(strict_types=1);

namespace Componenta\App\Cache;

use Componenta\App\ConfigKey;
use Componenta\Config\Config;
use Componenta\Stdlib\PathResolverInterface;
use RuntimeException;

final class CacheLayout
{
    public const string CURRENT = 'current.json';
    public const string GENERATIONS = 'generations';

    public string $buildDir {
        get => $this->paths->resolve($this->buildDirectory);
    }

    public string $devDir {
        get => $this->paths->resolve($this->devDirectory);
    }

    public string $runtimeDir {
        get => $this->paths->resolve($this->runtimeDirectory);
    }

    public string $current {
        get => $this->build(self::CURRENT);
    }

    public string $generations {
        get => $this->build(self::GENERATIONS);
    }

    public function __construct(
        private readonly PathResolverInterface $paths,
        private readonly string $buildDirectory,
        private readonly string $devDirectory,
        private readonly string $runtimeDirectory,
    ) {
        if ($this->buildDirectory === '' || $this->devDirectory === '' || $this->runtimeDirectory === '') {
            throw new RuntimeException('Cache directory path cannot be empty.');
        }
    }

    public static function fromConfig(Config $config, PathResolverInterface $paths): self
    {
        return new self(
            paths: $paths,
            buildDirectory: ConfigKey::DEFAULT_CACHE_BUILD_DIR,
            devDirectory: $config->string(ConfigKey::CACHE_DEV_DIR, ConfigKey::DEFAULT_CACHE_DEV_DIR),
            runtimeDirectory: $config->string(ConfigKey::CACHE_RUNTIME_DIR, ConfigKey::DEFAULT_CACHE_RUNTIME_DIR),
        );
    }

    public static function bootstrap(PathResolverInterface $paths): self
    {
        return new self(
            paths: $paths,
            buildDirectory: ConfigKey::DEFAULT_CACHE_BUILD_DIR,
            devDirectory: ConfigKey::DEFAULT_CACHE_DEV_DIR,
            runtimeDirectory: ConfigKey::DEFAULT_CACHE_RUNTIME_DIR,
        );
    }

    public static function defaults(PathResolverInterface $paths): self
    {
        return self::bootstrap($paths);
    }

    public function build(string $path): string
    {
        return $this->paths->resolve($this->buildDirectory . '/' . ltrim($path, '/\\'));
    }

    public function dev(string $path): string
    {
        return $this->paths->resolve($this->devDirectory . '/' . ltrim($path, '/\\'));
    }

    public function runtime(string $path): string
    {
        return $this->paths->resolve($this->runtimeDirectory . '/' . ltrim($path, '/\\'));
    }

    public function generation(string $generation): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $generation) !== 1) {
            throw new RuntimeException('Discovery generation id must be a SHA-256 hash.');
        }

        return $this->generations . '/' . $generation;
    }

    public function manifest(string $generation): string
    {
        return $this->generation($generation) . '/manifest.json';
    }
}
