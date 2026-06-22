<?php

declare(strict_types=1);

use Componenta\App\Config\AsConfig;
use Componenta\App\Config\AttributeConfigProvider;
use Componenta\App\Config\CachedAttributeConfigProvider;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Tokenizer\ClassInfo;

#[AsConfig]
final readonly class AttributeConfigProviderTestConfig
{
    public function __invoke(): array
    {
        return [
            'attribute-provider-test' => [
                'loaded' => true,
            ],
        ];
    }
}

describe('AttributeConfigProvider', function () {
    it('returns empty config when discovery is not available', function () {
        $provider = new AttributeConfigProvider();

        expect($provider->discovered)->toBeNull()
            ->and($provider())->toBe([]);
    });

    it('returns a new provider with discovered classes', function () {
        $classes = new ClassIterator([
            __FILE__ => new ClassInfo(AttributeConfigProviderTestConfig::class),
        ]);
        $provider = new AttributeConfigProvider();

        $configured = $provider->withDiscovered($classes);

        expect($configured)->not->toBe($provider)
            ->and($provider->discovered)->toBeNull()
            ->and($configured->discovered)->toBe($classes)
            ->and($configured())->toBe([
                'attribute-provider-test' => [
                    'loaded' => true,
                ],
            ]);
    });

    it('replaces stale cached attribute config', function () {
        $dir = sys_get_temp_dir() . '/componenta-attribute-cache-' . bin2hex(random_bytes(6));
        mkdir($dir);

        $cacheFile = $dir . '/attribute-config.dev.php';
        $baselineFile = $dir . '/discovery.dev.php';

        file_put_contents($cacheFile, "<?php\n\ndeclare(strict_types=1);\n\nreturn ['stale' => true];\n");
        file_put_contents($baselineFile, "<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n");
        touch($cacheFile, time() - 10);
        touch($baselineFile, time());

        $provider = new CachedAttributeConfigProvider(
            static fn(): array => ['fresh' => true],
            $cacheFile,
            $baselineFile,
        );

        try {
            expect($provider())->toBe(['fresh' => true])
                ->and(include $cacheFile)->toBe(['fresh' => true]);
        } finally {
            @unlink($cacheFile);
            @unlink($cacheFile . '.lock');
            foreach (glob($cacheFile . '.*.tmp') ?: [] as $tmp) {
                @unlink($tmp);
            }
            foreach (glob($cacheFile . '.*.bak') ?: [] as $backup) {
                @unlink($backup);
            }
            @unlink($baselineFile);
            @rmdir($dir);
        }
    });
});
