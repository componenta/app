<?php

declare(strict_types=1);

namespace Componenta\App\Build;

use Componenta\App\Config\ConfigDefinitionInterface;
use Componenta\App\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Stdlib\PathResolverInterface;
use RuntimeException;

/** Fresh source composition for build operations; never runs application bootloaders. */
final class ApplicationBuildRunner
{
    /** @param ConfigDefinitionInterface|callable(): ConfigDefinitionInterface $definition */
    public static function build(
        PathResolverInterface $paths,
        ConfigDefinitionInterface|callable $definition,
        ?Environment $environment = null,
        array $requiredArtifacts = [],
    ): PreparedBuild {
        $previousDirectory = getcwd();
        if ($previousDirectory === false || !chdir($paths->baseDir)) {
            throw new RuntimeException('Unable to select the application build directory.');
        }
        try {
            $result = ConfigFactory::create($paths, $definition, $environment, source: true, requiredArtifacts: $requiredArtifacts);
            $container = (new ContainerFactory())->create($result->config, $result->dependencies)->container;

            return $container->get(ApplicationBuildOrchestrator::class)->build();
        } finally {
            chdir($previousDirectory);
        }
    }

    public static function clean(PathResolverInterface $paths, ?Environment $environment = null): void
    {
        ConfigFactory::buildStore($paths, $environment)->clean();
    }

    private function __construct() {}
}
