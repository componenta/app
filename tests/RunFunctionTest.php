<?php

declare(strict_types=1);

use Componenta\App\Scope;
use Componenta\Stdlib\PathResolver;

use function Componenta\App\run;

if (!function_exists('Componenta\\App\\run')) {
    require_once dirname(__DIR__) . '/src/functions.php';
}

function createRunFunctionProjectRoot(string $suffix): string
{
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'componenta-app-run-' . $suffix . '-' . bin2hex(random_bytes(4));

    if (!mkdir($root . DIRECTORY_SEPARATOR . 'config', 0777, true) && !is_dir($root . DIRECTORY_SEPARATOR . 'config')) {
        throw new RuntimeException("Unable to create test project root {$root}.");
    }

    return $root;
}

function removeRunFunctionProjectRoot(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($path);
}

describe('run function', function () {
    it('loads the project container and runs the scoped application', function (): void {
        $root = createRunFunctionProjectRoot('success');
        $previousDirectory = getcwd();

        file_put_contents($root . '/config/container.php', <<<'PHP'
<?php

declare(strict_types=1);

use Componenta\App\AppFactoryInterface;
use Componenta\App\AppInterface;
use Componenta\App\Boot\BootContext;
use Componenta\App\Boot\BootloaderProviderInterface;
use Componenta\App\Boot\BootTargetFactoryInterface;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Scope\ScopeInterface;
use Psr\Container\ContainerInterface;

return new class implements ContainerInterface {
    public function get(string $id): mixed
    {
        return match ($id) {
            Config::class => new Config([]),
            AppFactoryInterface::class => new class implements AppFactoryInterface {
                public function createApp(ScopeInterface $scope, ContainerValue $container): AppInterface
                {
                    return new class implements AppInterface {
                        public function run(): ?int
                        {
                            file_put_contents(getcwd() . '/run.marker', '1');

                            return null;
                        }
                    };
                }
            },
            BootTargetFactoryInterface::class => new class implements BootTargetFactoryInterface {
                public function create(AppInterface $app, ScopeInterface $scope): object
                {
                    return $app;
                }
            },
            BootloaderProviderInterface::class => new class implements BootloaderProviderInterface {
                public function provideFor(BootContext $context): iterable
                {
                    return [];
                }
            },
            default => throw new RuntimeException("Missing test entry {$id}."),
        };
    }

    public function has(string $id): bool
    {
        return in_array($id, [
            Config::class,
            AppFactoryInterface::class,
            BootTargetFactoryInterface::class,
            BootloaderProviderInterface::class,
        ], true);
    }
};
PHP);

        try {
            run(Scope::CLI, new PathResolver($root));

            expect($root . '/run.marker')->toBeFile();
        } finally {
            if ($previousDirectory !== false) {
                chdir($previousDirectory);
            }

            removeRunFunctionProjectRoot($root);
        }
    });

    it('requires the project container file to return a PSR-11 container', function (): void {
        $root = createRunFunctionProjectRoot('invalid-container');
        $previousDirectory = getcwd();

        file_put_contents($root . '/config/container.php', <<<'PHP'
<?php

declare(strict_types=1);

return [];
PHP);

        try {
            expect(static fn () => run(Scope::CLI, new PathResolver($root)))
                ->toThrow(RuntimeException::class, 'config/container.php must return a PSR-11 container.');
        } finally {
            if ($previousDirectory !== false) {
                chdir($previousDirectory);
            }

            removeRunFunctionProjectRoot($root);
        }
    });
});
