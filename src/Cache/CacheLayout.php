<?php

declare(strict_types=1);

namespace Componenta\App\Cache;

use Componenta\App\ConfigKey;
use Componenta\Config\Config;
use Componenta\Stdlib\PathResolverInterface;
use RuntimeException;

final class CacheLayout
{
    public string $buildDir {
        get => $this->paths->resolve($this->buildDirectory);
    }

    public string $devDir {
        get => $this->paths->resolve($this->devDirectory);
    }

    public string $runtimeDir {
        get => $this->paths->resolve($this->runtimeDirectory);
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
}
