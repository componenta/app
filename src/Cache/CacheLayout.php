<?php

declare(strict_types=1);

namespace Componenta\App\Cache;

use Componenta\App\ConfigKey;
use Componenta\Stdlib\PathResolverInterface;
use Componenta\Config\Config;
use RuntimeException;

final class CacheLayout
{
    public const string CONFIG = 'config.cache.php';
    public const string ROUTES = 'routes.cache.php';
    public const string CONTAINER = 'container.cache.php';
    public const string CONTAINER_FACTORY = 'container.factory.php';
    public const string DI_PLANS = 'di-plans.cache.php';
    public const string DISCOVERY = 'discovery.cache.php';
    public const string POLICIES = 'policies.cache.php';
    public const string INTERCEPTORS = 'interceptors.cache.php';
    public const string PRELOAD = 'preload.php';
    public const string DEV_DISCOVERY = 'discovery.dev.php';
    public const string DEV_ATTRIBUTE_CONFIG = 'attribute-config.dev.php';
    public const string DEV_COMPILE = 'compile.dev.php';

    public string $buildDir {
        get => $this->paths->resolve($this->buildDirectory);
    }

    public string $devDir {
        get => $this->paths->resolve($this->devDirectory);
    }

    public string $runtimeDir {
        get => $this->paths->resolve($this->runtimeDirectory);
    }

    public string $config {
        get => $this->build(self::CONFIG);
    }

    public string $routes {
        get => $this->build(self::ROUTES);
    }

    public string $container {
        get => $this->build(self::CONTAINER);
    }

    public string $containerFactory {
        get => $this->build(self::CONTAINER_FACTORY);
    }

    public string $diPlans {
        get => $this->build(self::DI_PLANS);
    }

    public string $discovery {
        get => $this->build(self::DISCOVERY);
    }

    public string $policies {
        get => $this->build(self::POLICIES);
    }

    public string $interceptors {
        get => $this->build(self::INTERCEPTORS);
    }

    public string $preload {
        get => $this->build(self::PRELOAD);
    }

    public string $devDiscovery {
        get => $this->dev(self::DEV_DISCOVERY);
    }

    public string $devAttributeConfig {
        get => $this->dev(self::DEV_ATTRIBUTE_CONFIG);
    }

    public string $devCompile {
        get => $this->dev(self::DEV_COMPILE);
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
            paths:            $paths,
            buildDirectory:   ConfigKey::DEFAULT_CACHE_BUILD_DIR,
            devDirectory:     (string) $config->get(ConfigKey::CACHE_DEV_DIR, ConfigKey::DEFAULT_CACHE_DEV_DIR),
            runtimeDirectory: (string) $config->get(ConfigKey::CACHE_RUNTIME_DIR, ConfigKey::DEFAULT_CACHE_RUNTIME_DIR),
        );
    }

    public static function bootstrap(PathResolverInterface $paths): self
    {
        return new self(
            paths:            $paths,
            buildDirectory:   ConfigKey::DEFAULT_CACHE_BUILD_DIR,
            devDirectory:     ConfigKey::DEFAULT_CACHE_DEV_DIR,
            runtimeDirectory: ConfigKey::DEFAULT_CACHE_RUNTIME_DIR,
        );
    }

    public static function defaults(PathResolverInterface $paths): self
    {
        return self::bootstrap($paths);
    }

    public function build(string $file): string
    {
        return $this->paths->resolve($this->buildDirectory . '/' . ltrim($file, '/\\'));
    }

    public function dev(string $file): string
    {
        return $this->paths->resolve($this->devDirectory . '/' . ltrim($file, '/\\'));
    }

    public function runtime(string $file): string
    {
        return $this->paths->resolve($this->runtimeDirectory . '/' . ltrim($file, '/\\'));
    }
}
