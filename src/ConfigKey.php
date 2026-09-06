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
    /** Ordered list of independent application builder service IDs. */
    public const string BUILDERS = 'app.builders';

    /**
     * Legacy scope map consumed as a fallback while runtime integration
     * packages migrate to APP_ADAPTERS.
     */
    public const string APP_BY_SCOPE = 'app_by_scope';

    /**
     * Ordered list of bootloader class-strings driving the application's
     * boot phase. Each bootloader declares its own allowed scopes via
     * {@see \Componenta\Scope\ScopedInterface}.
     *
     * @see \Componenta\App\Runner
     */
    public const string BOOTLOADERS = 'bootloaders';

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
     * Transition-only key emitted by integration packages that have not yet
     * migrated their old compile contributor to StaticDiscoveryExtractorInterface.
     * The App 4 build/runtime deliberately ignores it.
     */
    public const string COMPILE_CACHE_CONTRIBUTORS = 'compile.cache_contributors';

    /**
     * Transition-only key emitted by integration packages that still publish
     * removed DI AOT roots. The runtime deliberately ignores it.
     */
    public const string AUTOWIRE_ENTRY_CONTRIBUTORS = 'compile.autowire_entry_contributors';

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
