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

    /** Shared lazy discovery of current source files, independent of the runtime class map. */
    public const string DISCOVERY_SOURCE = 'app.discovery.source';

    /** Optional selected snapshot. Guard this service ID, not an autowireable class name. */
    public const string PREPARED_BUILD = 'app.build.prepared';

    public const string DISCOVERY_ARTIFACT = 'app.discovery';

    public const string CONFIG_PROVIDERS_ARTIFACT = 'app.config-providers';

    /** DI service indicating that runtime discovery came from a valid map. */
    public const string DISCOVERY_PREPARED = 'app.discovery.prepared';

    public const string BOOT_ARTIFACT = 'app.boot';

    /**
     * Ordered list of bootloader class-strings driving the application's
     * boot phase. Each bootloader declares its own allowed scopes via
     * {@see \Componenta\Scope\ScopedInterface}.
     *
     * @see \Componenta\App\Runner
     */
    public const string BOOTLOADERS = 'bootloaders';

    /** Map of scope values to application service IDs. */
    public const string APPLICATIONS = 'app.applications';

    /**
     * Ordered list of boot target adapter class-strings. Each adapter wraps an
     * app instance into the target object expected by runtime bootloaders.
     */
    public const string BOOT_TARGET_ADAPTERS = 'boot.target_adapters';

    /**
     * Cache directory paths (relative to PathResolverInterface::baseDir or absolute).
     *
     * Build artifacts belong to the store; development/runtime caches are separate.
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
