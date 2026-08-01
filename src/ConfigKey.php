<?php

declare(strict_types=1);

namespace Componenta\App;

/**
 * Configuration keys for Componenta App.
 *
 * @example Usage in config files
 * ```php
 * use Componenta\App\ConfigKey;
 *
 * return [
 *     ConfigKey::DEPENDENCIES => [
 *         ConfigKey::FACTORIES => [...],
 *         ConfigKey::ALIASES => [...],
 *     ],
 * ];
 * ```
 */
class ConfigKey extends \Componenta\Config\ConfigKey
{
    /** Discovered classes from class finder */
    public const string DISCOVERED = 'discovered';

    /**
     * Ordered list of bootloader class-strings driving the application's
     * boot phase. Each bootloader declares its own allowed scopes via
     * {@see \Componenta\Scope\ScopedInterface}.
     *
     * @see \Componenta\App\Runner
     */
    public const string BOOTLOADERS = 'bootloaders';

    /**
     * Compiled list of `#[Boot]` method invocations produced by app:build.
     *
     * Development discovers boot methods through class scanning. Production
     * reads this metadata and invokes boot methods without reflection scans.
     */
    public const string BOOT_INVOCATIONS = 'boot.invocations';

    /**
     * Ordered list of app adapter class-strings. Runtime integration packages
     * append their adapters here instead of replacing the base app factory.
     */
    public const string APP_ADAPTERS = 'app.adapters';

    /**
     * Ordered list of boot target adapter class-strings. Each adapter wraps an
     * app instance into the target object expected by runtime bootloaders.
     */
    public const string BOOT_TARGET_ADAPTERS = 'boot.target_adapters';

    /**
     * Ordered list of compile cache contributor service ids.
     *
     * Integration packages use contributors to append their own compiled
     * discovery metadata without making the base app package depend on them.
     */
    public const string COMPILE_CACHE_CONTRIBUTORS = 'compile.cache_contributors';

    /**
     * Cache directory paths (relative to PathResolverInterface::baseDir or absolute).
     *
     * Build cache is intentionally not configurable through application config:
     * the production config cache must be found before application config exists.
     * Dev/runtime caches are read after config load, so they can be relocated here.
     */
    public const string CACHE_DEV_DIR = 'cache.dev_dir';
    public const string CACHE_RUNTIME_DIR = 'cache.runtime_dir';

    /**
     * Default values used when the keys above are absent from config.
     *
     * Directory defaults are relative to the active path resolver base directory.
     */
    public const string DEFAULT_CACHE_BUILD_DIR = 'var/cache/build';
    public const string DEFAULT_CACHE_DEV_DIR = 'var/cache/dev';
    public const string DEFAULT_CACHE_RUNTIME_DIR = 'var/cache/runtime';
}
