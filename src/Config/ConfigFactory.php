<?php

declare(strict_types=1);

namespace Componenta\App\Config;

use Componenta\App\Cache\CacheLayout;
use Componenta\App\Discovery\Compile\CompileCache;
use Componenta\App\Discovery\Discovery;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\Config;
use Componenta\Config\ConfigLoader;
use Componenta\Config\Environment;
use Componenta\Config\Loader\EnvLoader;
use Componenta\Stdlib\PathResolverInterface;
use RuntimeException;

use function Componenta\Config\populate_env;

final class ConfigFactory
{
    private function __construct() {}

    /** @param ConfigDefinitionInterface|callable(): ConfigDefinitionInterface $definition */
    public static function create(
        PathResolverInterface $paths,
        ConfigDefinitionInterface|callable $definition,
        ?Environment $environment = null,
        bool $loadCachedCompileDelta = true,
    ): ConfigFactoryResult {
        $bootstrapCache = CacheLayout::bootstrap($paths);
        $env = $environment ?? self::loadApplicationEnvironment($paths->baseDir);

        if ($env->get('APP_ENV', 'development') !== 'development') {
            $cached = ConfigLoader::loadFromFile($bootstrapCache->config);
            return new ConfigFactoryResult(config: new Config($cached->toArray(), $env));
        }

        $definition = self::definition($definition);
        $providers = self::providers($definition->providers);
        $cache = CacheLayout::fromConfig(ConfigLoader::load($env, ...$providers), $paths);
        $discovery = $definition->discovery;
        $discovered = null;
        $cachedDelta = null;
        $compileCache = null;
        $discoveryCacheFile = null;

        if ($discovery !== null) {
            $discoveryCacheFile = $cache->devDiscovery;
            $discovered = new Discovery($discoveryCacheFile)->discover(
                dirs: self::resolveDirectories($paths, $discovery->directories),
                exclude: $discovery->exclude,
            );
            $compileCache = new CompileCache(cacheFile: $cache->devCompile, baselineFile: $discoveryCacheFile);
            if ($loadCachedCompileDelta) {
                $cachedDelta = $compileCache->load();
            }
        }

        $providers = self::prepareProviders(
            providers: $providers,
            discovered: $discovered,
            cache: $cache,
            discoveryCacheFile: $discoveryCacheFile,
        );
        if ($cachedDelta !== null) {
            $providers = self::withCachedDelta($providers, $cachedDelta);
        }

        return new ConfigFactoryResult(
            config: ConfigLoader::load($env, ...$providers),
            discovered: $discovered,
        );
    }

    private static function loadApplicationEnvironment(string $baseDir): Environment
    {
        $process = self::processEnvironmentData();
        $bootstrap = self::readEnvironmentFiles($baseDir, ['.env', '.env.local']);
        $name = $process['APP_ENV'] ?? $bootstrap['APP_ENV'] ?? 'development';

        if (!is_string($name) || $name === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $name) !== 1) {
            throw new RuntimeException('APP_ENV must contain only letters, digits, underscores, or hyphens.');
        }

        $loaded = self::readEnvironmentFiles($baseDir, array_values(array_unique([
            '.env', '.env.local', '.env.' . $name, '.env.' . $name . '.local',
        ])));

        // Deployment values win even with the currently published legacy
        // populate_env() implementation, which only checks $_ENV itself.
        $toPopulate = array_diff_key($loaded, $process);
        if ($toPopulate !== []) {
            populate_env($toPopulate, override: false);
        }

        return new Environment([...$loaded, ...$process]);
    }

    /** @return array<string, mixed> */
    private static function processEnvironmentData(): array
    {
        $data = [];
        $native = getenv();
        if (is_array($native)) {
            foreach ($native as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $data[$key] = $value;
                }
            }
        }
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && (is_scalar($value) || $value === null)) {
                $data[$key] = $value;
            }
        }
        foreach ($_ENV as $key => $value) {
            if (is_string($key)) {
                $data[$key] = $value;
            }
        }
        return $data;
    }

    /** @param list<string> $files @return array<string, string> */
    private static function readEnvironmentFiles(string $baseDir, array $files): array
    {
        // componenta/config after the environment-hardening release exposes a
        // pure exact-file reader. This fallback keeps app main installable with
        // the currently published config package until that release is tagged.
        if (method_exists(EnvLoader::class, 'read')) {
            /** @var array<string, string>|null $loaded */
            $loaded = (new EnvLoader($baseDir, null, $files))->read();
            return $loaded ?? [];
        }

        $loaded = [];
        foreach ($files as $filename) {
            $path = $baseDir . DIRECTORY_SEPARATOR . $filename;
            if (!is_file($path)) {
                continue;
            }
            $content = file_get_contents($path);
            if ($content === false) {
                throw new RuntimeException("Unable to read environment file: {$path}");
            }
            $loaded = [...$loaded, ...self::parseEnvironmentContent($content, $path)];
        }
        return $loaded;
    }

    /** @return array<string, string> */
    private static function parseEnvironmentContent(string $content, string $path): array
    {
        $data = [];
        foreach (explode("\n", $content) as $number => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $position = strpos($line, '=');
            if ($position === false) {
                throw new RuntimeException(sprintf('Invalid environment assignment in %s on line %d.', $path, $number + 1));
            }
            $key = trim(substr($line, 0, $position));
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) !== 1) {
                throw new RuntimeException(sprintf('Invalid environment key in %s on line %d.', $path, $number + 1));
            }
            $value = trim(substr($line, $position + 1));
            if (strlen($value) >= 2
                && (($value[0] === '"' && str_ends_with($value, '"'))
                    || ($value[0] === "'" && str_ends_with($value, "'")))
            ) {
                $quote = $value[0];
                $value = substr($value, 1, -1);
                if ($quote === '"') {
                    $value = str_replace(['\\n', '\\r', '\\t', '\\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], $value);
                }
            }
            $data[$key] = $value;
        }
        return $data;
    }

    /** @param ConfigDefinitionInterface|callable(): ConfigDefinitionInterface $definition */
    private static function definition(ConfigDefinitionInterface|callable $definition): ConfigDefinitionInterface
    {
        if ($definition instanceof ConfigDefinitionInterface) {
            return $definition;
        }
        $resolved = $definition();
        if (!$resolved instanceof ConfigDefinitionInterface) {
            throw new RuntimeException(sprintf('Config definition loader must return %s, got %s.', ConfigDefinitionInterface::class, get_debug_type($resolved)));
        }
        return $resolved;
    }

    /** @param iterable<callable(): array> $providers @return list<callable(): array> */
    private static function providers(iterable $providers): array
    {
        $result = [];
        foreach ($providers as $provider) {
            if (!is_callable($provider)) {
                throw new RuntimeException(sprintf('Config provider must be callable, got %s.', get_debug_type($provider)));
            }
            $result[] = $provider;
        }
        return $result;
    }

    /** @param list<callable(): array> $providers @return list<callable(): array> */
    private static function prepareProviders(array $providers, ?ClassIteratorInterface $discovered, CacheLayout $cache, ?string $discoveryCacheFile): array
    {
        $prepared = [];
        foreach ($providers as $provider) {
            if ($provider instanceof DiscoveryAwareConfigProviderInterface) {
                $provider = $provider->withDiscovered($discovered);
            }
            if ($provider instanceof AttributeConfigProvider && $discovered !== null && $discoveryCacheFile !== null) {
                $provider = new CachedAttributeConfigProvider(inner: $provider(...), cacheFile: $cache->devAttributeConfig, baselineFile: $discoveryCacheFile);
            }
            $prepared[] = $provider;
        }
        return $prepared;
    }

    /** @param list<callable(): array> $providers @param array<string, mixed> $cachedDelta @return list<callable(): array> */
    private static function withCachedDelta(array $providers, array $cachedDelta): array
    {
        $deltaProvider = static fn (): array => $cachedDelta;
        foreach ($providers as $index => $provider) {
            if ($provider instanceof CachedAttributeConfigProvider || $provider instanceof AttributeConfigProvider) {
                array_splice($providers, $index + 1, 0, [$deltaProvider]);
                return $providers;
            }
        }
        array_unshift($providers, $deltaProvider);
        return $providers;
    }

    /** @param list<string> $directories @return list<string> */
    private static function resolveDirectories(PathResolverInterface $paths, array $directories): array
    {
        $resolved = [];
        foreach ($directories as $directory) {
            $resolved[] = $paths->resolve($directory);
        }
        return $resolved;
    }
}
