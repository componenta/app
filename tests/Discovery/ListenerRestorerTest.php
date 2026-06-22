<?php

declare(strict_types=1);

use Componenta\App\Discovery\ListenerRestorer;
use Componenta\Stdlib\PathResolver;
use Componenta\ClassFinder\ClassListenerInterface;
use Componenta\ClassFinder\ClassListenerProviderInterface;
use Componenta\Config\Config;
use Componenta\Tokenizer\ClassInfo;

final class ListenerRestorerRecordingListener implements ClassListenerInterface
{
    /** @var list<class-string> */
    public array $handled = [];

    public function handle(ClassInfo $info): void
    {
        $this->handled[] = $info->fullyQualifiedName;
    }
}

final readonly class ListenerRestorerProvider implements ClassListenerProviderInterface
{
    /**
     * @param list<ClassListenerInterface> $listeners
     */
    public function __construct(
        private array $listeners,
    ) {}

    public function getClassListeners(): iterable
    {
        return $this->listeners;
    }
}

final class ListenerRestorerSidecarTarget {}

final class ListenerRestorerInlineTarget {}

function listenerRestorerCacheFile(array $payload): string
{
    $file = tempnam(sys_get_temp_dir(), 'componenta_discovery_');

    if ($file === false) {
        throw new RuntimeException('Failed to create temporary discovery cache.');
    }

    file_put_contents(
        $file,
        "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($payload, true) . ";\n",
    );

    return $file;
}

describe('Discovery listener restorer', function () {
    it('restores classes from a discovery sidecar cache', function () {
        $listener = new ListenerRestorerRecordingListener();
        $file = listenerRestorerCacheFile([
            'version' => ListenerRestorer::CACHE_VERSION,
            'cache' => [
                'classes' => [ListenerRestorerSidecarTarget::class],
                'targets' => [],
            ],
        ]);

        try {
            $restorer = new ListenerRestorer(
                new ListenerRestorerProvider([$listener]),
                new Config([ListenerRestorer::CACHE_FILE_KEY => basename($file)]),
                new PathResolver(dirname($file)),
            );

            $restorer->restore(includeDevOnly: true);

            expect($listener->handled)->toBe([ListenerRestorerSidecarTarget::class]);
        } finally {
            @unlink($file);
        }
    });

    it('keeps inline discovery cache ahead of sidecar cache', function () {
        $listener = new ListenerRestorerRecordingListener();
        $file = listenerRestorerCacheFile([
            'version' => ListenerRestorer::CACHE_VERSION,
            'cache' => [
                'classes' => [ListenerRestorerSidecarTarget::class],
                'targets' => [],
            ],
        ]);

        try {
            $restorer = new ListenerRestorer(
                new ListenerRestorerProvider([$listener]),
                new Config([
                    ListenerRestorer::CACHE_KEY => [
                        'classes' => [ListenerRestorerInlineTarget::class],
                        'targets' => [],
                    ],
                    ListenerRestorer::CACHE_FILE_KEY => basename($file),
                ]),
                new PathResolver(dirname($file)),
            );

            $restorer->restore(includeDevOnly: true);

            expect($listener->handled)->toBe([ListenerRestorerInlineTarget::class]);
        } finally {
            @unlink($file);
        }
    });

    it('does not report cache when the sidecar is missing', function () {
        $restorer = new ListenerRestorer(
            new ListenerRestorerProvider([new ListenerRestorerRecordingListener()]),
            new Config([ListenerRestorer::CACHE_FILE_KEY => 'missing-componenta-discovery-cache.php']),
            new PathResolver(sys_get_temp_dir()),
        );

        expect($restorer->hasCache())->toBeFalse();
    });
});
