<?php

declare(strict_types=1);

namespace Componenta\App\Build;

use Componenta\App\Cache\CacheLayout;
use Componenta\App\Config\ComposerPackageConfigProvider;
use Componenta\App\Config\ConfigDefinitionInterface;
use Componenta\App\Discovery\Artifact\ArtifactRepository;
use Componenta\App\Discovery\DiscoverySnapshot;
use Componenta\App\Discovery\StaticDiscoveryExtractorInterface;
use Componenta\ClassFinder\ClassFinder;
use Componenta\Stdlib\PathResolverInterface;
use RuntimeException;

final class ApplicationBuilder
{
    /**
     * @param ConfigDefinitionInterface|callable(): ConfigDefinitionInterface $definition
     */
    public function build(
        PathResolverInterface $paths,
        ConfigDefinitionInterface|callable $definition,
    ): BuildResult {
        $definition = $this->definition($definition);
        $sections = [];
        $sourceIndex = 0;

        foreach ($definition->providers as $provider) {
            if (!$provider instanceof ComposerPackageConfigProvider) {
                continue;
            }

            $sections['providers.' . $sourceIndex] = $provider->classes();
            $sourceIndex++;
        }

        if ($definition->discovery !== null) {
            $classes = new ClassFinder()->find(
                array_map($paths->resolve(...), $definition->discovery->directories),
                $definition->discovery->exclude,
            );
            $sections['classes'] = DiscoverySnapshot::capture($classes, $paths);

            $extractorKeys = [];
            foreach ($definition->discovery->extractors as $extractor) {
                if (!$extractor instanceof StaticDiscoveryExtractorInterface) {
                    throw new RuntimeException(sprintf(
                        'Discovery extractor must implement %s, got %s.',
                        StaticDiscoveryExtractorInterface::class,
                        get_debug_type($extractor),
                    ));
                }

                $key = $extractor->key();
                if (isset($extractorKeys[$key])) {
                    throw new RuntimeException(sprintf('Duplicate discovery extractor key "%s".', $key));
                }
                $extractorKeys[$key] = true;
                $sections['semantic.' . $key] = $extractor->extract($classes);
            }
        }

        $published = (new ArtifactRepository(CacheLayout::bootstrap($paths)))->publish($sections);

        return new BuildResult(
            generation: $published->generation,
            directory: $published->directory,
            manifest: $published->manifest,
            sections: array_keys($sections),
        );
    }

    /**
     * @param ConfigDefinitionInterface|callable(): ConfigDefinitionInterface $definition
     */
    private function definition(ConfigDefinitionInterface|callable $definition): ConfigDefinitionInterface
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
}
