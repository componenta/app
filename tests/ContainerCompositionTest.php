<?php

declare(strict_types=1);

use Componenta\App\Config\ConfigDefinition;
use Componenta\App\Config\ConfigFactory as ApplicationConfigFactory;
use Componenta\App\Config\DiscoveryDefinition;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\Config;
use Componenta\Config\ConfigKey;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Stdlib\PathResolver;
use Componenta\Stdlib\PathResolverInterface;

function containerCompositionRoot(): string
{
    $root = str_replace('\\', '/', sys_get_temp_dir())
        . '/componenta_app_container_composition_'
        . bin2hex(random_bytes(4));
    if (!mkdir($root . '/src', 0o755, recursive: true) && !is_dir($root . '/src')) {
        throw new RuntimeException('Failed to create App container composition test root.');
    }

    return $root;
}

function removeContainerCompositionRoot(string $root): void
{
    if (!is_dir($root)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo) {
            throw new RuntimeException('Unexpected container composition test filesystem entry.');
        }

        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}

it('passes one ConfigComposition to the same DI ContainerFactory in every environment', function (): void {
    $root = containerCompositionRoot();
    file_put_contents($root . '/src/Example.php', "<?php\n\ndeclare(strict_types=1);\n\nfinal class ContainerCompositionExample {}\n");
    $paths = new PathResolver($root);
    $runtime = static fn (): string => 'runtime';
    $definition = new ConfigDefinition(
        providers: [
            static fn (): array => [
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::SERVICES => ['runtime.closure' => $runtime],
                ],
            ],
        ],
        discovery: new DiscoveryDefinition(directories: ['src']),
    );

    try {
        foreach (['development', 'production'] as $mode) {
            $environment = new Environment(['APP_ENV' => $mode]);
            $result = ApplicationConfigFactory::create(
                paths: $paths,
                definition: $definition,
                environment: $environment,
            );
            $value = (new ContainerFactory())->create(
                $result->composition->config,
                $result->composition->dependencies,
            );

            expect($value->config)->toBe($result->config)
                ->and($value->container->get(Config::class))->toBe($result->config)
                ->and($value->container->get(Environment::class))->toBe($environment)
                ->and($value->container->get(PathResolverInterface::class))->toBe($paths)
                ->and($value->container->get(ClassIteratorInterface::class))->toBe($result->discovered)
                ->and($value->container->get('runtime.closure'))->toBe($runtime);
        }
    } finally {
        removeContainerCompositionRoot($root);
    }
});
