<?php

declare(strict_types=1);

use Componenta\App\Config\AsConfig;
use Componenta\App\Config\AttributeConfigProvider;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Tokenizer\ClassInfo;

#[AsConfig]
final readonly class AttributeConfigProviderTestConfig
{
    /** @return array<string, mixed> */
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
            ->and(iterator_to_array($provider()))->toBe([]);
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
            ->and(iterator_to_array($configured()))->toBe([[
                'attribute-provider-test' => [
                    'loaded' => true,
                ],
            ]]);
    });
});
