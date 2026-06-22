<?php

declare(strict_types=1);

use Componenta\App\Boot\BootMethodInvocation;
use Componenta\App\Boot\BootInvocationRunner;
use Componenta\App\Boot\BootloaderProvider;
use Componenta\App\Boot\BootTargetFactory;
use Componenta\App\Boot\Compile\BootInvocationCompiler;
use Componenta\App\Boot\CompiledBootInvocationBootloader;
use Componenta\App\Boot\DateTimeBootloader;
use Componenta\App\Boot\ClassDiscoveryBootloader;
use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\App\ConfigProvider;
use Componenta\App\Discovery\Compile\CompileCache;
use Componenta\App\Discovery\Compile\DiPlanBuilder;
use Componenta\App\Discovery\Compile\DiscoveryCompiler;
use Componenta\App\Discovery\Compile\DiscoveryCompilerFactory;
use Componenta\ClassFinder\Compile\ConfigKey as CompileConfigKey;
use Componenta\App\Discovery\ListenerCompiler;
use Componenta\App\Discovery\ListenerRestorer;
use Componenta\ClassFinder\ConfigKey as ClassFinderConfigKey;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\DI\ConfigKey as DependencyConfigKey;
use Componenta\Stdlib\PathResolver;
use Componenta\Stdlib\PathResolverInterface;
use Psr\Container\ContainerInterface;

final readonly class AppConfigProviderTestContainer implements ContainerInterface
{
    /**
     * @param array<string, mixed> $entries
     */
    public function __construct(private array $entries) {}

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new RuntimeException("Missing container entry: {$id}");
        }

        return $this->entries[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}

describe('app ConfigProvider', function () {
    it('registers framework bootloaders, discovery listeners, and compile cache factory', function () {
        $config = (new ConfigProvider())();

        expect($config[AppConfigKey::BOOTLOADERS])->toBe([
            DateTimeBootloader::class,
            ClassDiscoveryBootloader::class,
            CompiledBootInvocationBootloader::class,
        ])->and($config[ClassFinderConfigKey::LISTENERS])->toBe([
            BootMethodInvocation::class,
        ])->and($config[CompileConfigKey::LISTENER_COMPILERS])->toBe([
            BootInvocationCompiler::class,
        ])->and($config[DependencyConfigKey::DEPENDENCIES][DependencyConfigKey::AUTOWIRES])->toBe([
            Componenta\App\AppFactory::class,
            BootMethodInvocation::class,
            BootInvocationRunner::class,
            BootInvocationCompiler::class,
            BootloaderProvider::class,
            CompiledBootInvocationBootloader::class,
            DateTimeBootloader::class,
            DiPlanBuilder::class,
            ListenerCompiler::class,
            ListenerRestorer::class,
            ClassDiscoveryBootloader::class,
        ])->and($config[DependencyConfigKey::DEPENDENCIES][DependencyConfigKey::FACTORIES])->toHaveKeys([
            BootTargetFactory::class,
            DiscoveryCompiler::class,
            CompileCache::class,
        ])->and($config[DependencyConfigKey::DEPENDENCIES][DependencyConfigKey::FACTORIES][DiscoveryCompiler::class])
            ->toBe(DiscoveryCompilerFactory::class);
    });

    it('builds the development compile cache from configured cache paths', function () {
        $root = str_replace('\\', '/', sys_get_temp_dir()) . '/componenta_app_config_provider_' . bin2hex(random_bytes(4));
        mkdir($root . '/cache/dev', recursive: true);

        try {
            $config = (new ConfigProvider())();
            $factory = $config[DependencyConfigKey::DEPENDENCIES][DependencyConfigKey::FACTORIES][CompileCache::class];

            $container = new AppConfigProviderTestContainer([
                Config::class => new Config([
                    AppConfigKey::CACHE_DEV_DIR => 'cache/dev',
                ]),
                PathResolverInterface::class => new PathResolver($root),
            ]);

            $compileCache = $factory(new ContainerValue($container));

            expect($compileCache)->toBeInstanceOf(CompileCache::class)
                ->and($compileCache->cacheFile())->toBe($root . '/cache/dev/compile.dev.php');
        } finally {
            if (is_dir($root . '/cache/dev')) {
                rmdir($root . '/cache/dev');
            }

            if (is_dir($root . '/cache')) {
                rmdir($root . '/cache');
            }

            if (is_dir($root)) {
                rmdir($root);
            }
        }
    });
});
