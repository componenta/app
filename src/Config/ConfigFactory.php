<?php

declare(strict_types=1);

namespace Componenta\App\Config;

use Componenta\App\Cache\CacheLayout;
use Componenta\App\Discovery\Artifact\ArtifactRepository;
use Componenta\App\Discovery\DiscoverySnapshot;
use Componenta\App\Discovery\StaticDiscoveryExtractorInterface;
use Componenta\ClassFinder\ClassFinder;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\ConfigFactory as RuntimeConfigFactory;
use Componenta\Config\ConfigKey;
use Componenta\Config\Environment;
use Componenta\Config\Loader\EnvLoader;
use Componenta\Stdlib\PathResolverInterface;
use RuntimeException;
use Throwable;

final class ConfigFactory
{
    private function __construct()
    {
    }

    /**
     * @param ConfigDefinitionInterface|callable(): ConfigDefinitionInterface $definition
     */
    public static function create(
        PathResolverInterface $paths,
        ConfigDefinitionInterface|callable $definition,
        ?Environment $environment = null,
    ): ConfigFactoryResult {
        $environment ??= new EnvLoader($paths->baseDir)->load();
        $definition = self::definition($definition);
        $production = $environment->match('APP_ENV', 'production', false);
        $artifacts = new ArtifactRepository(CacheLayout::bootstrap($paths));
        $diagnostics = [];
        $discovered = self::discovery(
            paths: $paths,
            definition: $definition,
            production: $production,
            artifacts: $artifacts,
            diagnostics: $diagnostics,
        );
        $providers = self::providers(
            definition: $definition,
            discovered: $discovered,
            semantic: self::semanticContributions(
                definition: $definition,
                discovered: $discovered,
                production: $production,
                artifacts: $artifacts,
                diagnostics: $diagnostics,
            ),
            production: $production,
            artifacts: $artifacts,
            diagnostics: $diagnostics,
        );
        $providers[] = self::runtimeServices($paths, $discovered);
        $composition = (new RuntimeConfigFactory())->create($environment, ...$providers);

        return new ConfigFactoryResult(
            composition: $composition,
            discovered: $discovered,
            diagnostics: $diagnostics,
        );
    }

    /**
     * @param ConfigDefinitionInterface|callable(): ConfigDefinitionInterface $definition
     */
    private static function definition(ConfigDefinitionInterface|callable $definition): ConfigDefinitionInterface
    {
        if ($definition instanceof ConfigDefinitionInterface) {
            return $definition;
        }

        $resolved = $definition();
        if (!$resolved instanceof ConfigDefinitionInterface) {
            throw new RuntimeException(sprintf(
                'Config definition loader must return %s, got %s.',
                ConfigDefinitionInterface::class,
                get_debug_type($resolved),
            ));
        }

        return $resolved;
    }

    /**
     * @param list<string> $diagnostics
     */
    private static function discovery(
        PathResolverInterface $paths,
        ConfigDefinitionInterface $definition,
        bool $production,
        ArtifactRepository $artifacts,
        array &$diagnostics,
    ): ?ClassIteratorInterface {
        $discovery = $definition->discovery;
        if ($discovery === null) {
            return null;
        }

        if ($production) {
            $snapshot = $artifacts->read('classes', $diagnostics);
            if ($snapshot !== null) {
                try {
                    return DiscoverySnapshot::materialize($snapshot, $paths);
                } catch (Throwable $e) {
                    $diagnostics[] = sprintf(
                        'Discovery artifact section "classes" is invalid: %s',
                        $e->getMessage(),
                    );
                }
            }
        }

        return new ClassFinder()->find(
            self::resolveDirectories($paths, $discovery->directories),
            $discovery->exclude,
        );
    }

    /**
     * @param list<string> $diagnostics
     * @param list<array<array-key, mixed>> $semantic
     * @return list<callable(): mixed>
     */
    private static function providers(
        ConfigDefinitionInterface $definition,
        ?ClassIteratorInterface $discovered,
        array $semantic,
        bool $production,
        ArtifactRepository $artifacts,
        array &$diagnostics,
    ): array {
        $providers = [];
        $sourceIndex = 0;
        $semanticInserted = false;

        foreach ($definition->providers as $provider) {
            if ($provider instanceof ComposerPackageConfigProvider) {
                $classes = null;
                if ($production) {
                    $classes = $artifacts->read('providers.' . $sourceIndex, $diagnostics);
                    if ($classes !== null && !ComposerPackageConfigProvider::isClassList($classes)) {
                        $diagnostics[] = sprintf(
                            'Discovery artifact section "providers.%d" is invalid.',
                            $sourceIndex,
                        );
                        $classes = null;
                    }
                }

                if ($classes === null) {
                    $classes = $provider->classes();
                }

                foreach ($provider->materialize($classes) as $materialized) {
                    $prepared = self::prepareProvider($materialized, $discovered);
                    $providers[] = $prepared;
                    if ($prepared instanceof AttributeConfigProvider && !$semanticInserted) {
                        self::appendSemantic($providers, $semantic);
                        $semanticInserted = true;
                    }
                }
                $sourceIndex++;
                continue;
            }

            if (!is_callable($provider)) {
                throw new RuntimeException(sprintf(
                    'Config provider must be callable or %s, got %s.',
                    ComposerPackageConfigProvider::class,
                    get_debug_type($provider),
                ));
            }

            $prepared = self::prepareProvider($provider, $discovered);
            $providers[] = $prepared;
            if ($prepared instanceof AttributeConfigProvider && !$semanticInserted) {
                self::appendSemantic($providers, $semantic);
                $semanticInserted = true;
            }
        }

        if (!$semanticInserted && $semantic !== []) {
            $semanticProviders = [];
            self::appendSemantic($semanticProviders, $semantic);
            array_unshift($providers, ...$semanticProviders);
        }

        return $providers;
    }

    /**
     * @param list<string> $diagnostics
     * @return list<array<array-key, mixed>>
     */
    private static function semanticContributions(
        ConfigDefinitionInterface $definition,
        ?ClassIteratorInterface $discovered,
        bool $production,
        ArtifactRepository $artifacts,
        array &$diagnostics,
    ): array {
        if ($definition->discovery === null || $discovered === null) {
            return [];
        }

        $contributions = [];
        $keys = [];

        foreach ($definition->discovery->extractors as $extractor) {
            if (!$extractor instanceof StaticDiscoveryExtractorInterface) {
                throw new RuntimeException(sprintf(
                    'Discovery extractor must implement %s, got %s.',
                    StaticDiscoveryExtractorInterface::class,
                    get_debug_type($extractor),
                ));
            }

            $key = $extractor->key();
            if (isset($keys[$key])) {
                throw new RuntimeException(sprintf('Duplicate discovery extractor key "%s".', $key));
            }
            $keys[$key] = true;

            $contribution = $production
                ? $artifacts->read('semantic.' . $key, $diagnostics)
                : null;
            $contributions[] = $contribution ?? $extractor->extract($discovered);
        }

        return $contributions;
    }

    /**
     * @param list<callable(): mixed> $providers
     * @param list<array<array-key, mixed>> $semantic
     */
    private static function appendSemantic(array &$providers, array $semantic): void
    {
        foreach ($semantic as $contribution) {
            $providers[] = static fn (): array => $contribution;
        }
    }

    private static function prepareProvider(
        callable $provider,
        ?ClassIteratorInterface $discovered,
    ): callable {
        return $provider instanceof DiscoveryAwareConfigProviderInterface
            ? $provider->withDiscovered($discovered)
            : $provider;
    }

    private static function runtimeServices(
        PathResolverInterface $paths,
        ?ClassIteratorInterface $discovered,
    ): callable {
        return static function () use ($paths, $discovered): array {
            $services = [PathResolverInterface::class => $paths];
            if ($discovered !== null) {
                $services[ClassIteratorInterface::class] = $discovered;
            }

            return [
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::SERVICES => $services,
                ],
            ];
        };
    }

    /**
     * @param list<string> $directories
     * @return list<string>
     */
    private static function resolveDirectories(PathResolverInterface $paths, array $directories): array
    {
        return array_map($paths->resolve(...), $directories);
    }
}
