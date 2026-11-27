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

        expect(iterator_to_array($provider()))->toBe([]);
    });

    it('returns a new provider with discovered classes', function () {
        $classes = new ClassIterator([
            __FILE__ => new ClassInfo(AttributeConfigProviderTestConfig::class),
        ]);
        $provider = new AttributeConfigProvider();

        $configured = $provider->withDiscovered($classes);

        expect($configured)->not->toBe($provider)
            ->and(iterator_to_array($provider()))->toBe([])
            ->and(iterator_to_array($configured()))->toBe([[
                'attribute-provider-test' => [
                    'loaded' => true,
                ],
            ]]);
    });
});


it('invokes a discovered configuration class once even when the declaration is encountered repeatedly', function (): void {
    $classes = new ClassIterator([
        'first.php' => new ClassInfo(AttributeConfigProviderTestConfig::class),
        'second.php' => new ClassInfo(strtolower(AttributeConfigProviderTestConfig::class)),
    ]);

    $provider = (new AttributeConfigProvider())->withDiscovered($classes);
    expect(iterator_to_array($provider(), false))->toBe([
        ['attribute-provider-test' => ['loaded' => true]],
    ]);
    // A separate invocation is a separate composition, not a cached result.
    expect(iterator_to_array($provider(), false))->toBe([
        ['attribute-provider-test' => ['loaded' => true]],
    ]);
});


#[AsConfig]
final class AttributeConfigDeferredFailure
{
    public static ?DomainException $failure = null;
    public static bool $afterContribution = false;

    public function __invoke(): iterable
    {
        if (self::$afterContribution) {
            yield ['first' => true];
        }
        throw self::$failure ?? new DomainException('Deferred configuration failure');
    }
}

it('reports the discovered provider and preserves the original failure during deferred iteration', function (bool $afterContribution): void {
    $failure = new DomainException('Deferred configuration failure');
    AttributeConfigDeferredFailure::$failure = $failure;
    AttributeConfigDeferredFailure::$afterContribution = $afterContribution;
    try {
        $provider = new AttributeConfigProvider(new ClassIterator([
            new ClassInfo(AttributeConfigDeferredFailure::class),
        ]));
        $contributions = [];
        try {
            foreach ($provider() as $contribution) {
                $contributions[] = $contribution;
            }
            test()->fail('The deferred failure must propagate.');
        } catch (\Componenta\Config\Exception\ConfigException $error) {
            expect($error->getMessage())->toContain(AttributeConfigDeferredFailure::class)
                ->and($error->getPrevious())->toBe($failure)
                ->and($contributions)->toBe($afterContribution ? [['first' => true]] : []);
        }
    } finally {
        AttributeConfigDeferredFailure::$failure = null;
        AttributeConfigDeferredFailure::$afterContribution = false;
    }
})->with([false, true]);

#[AsConfig]
class AttributeConfigSnapshotFirstProvider
{
    public static string $value = 'initial';
    private int $invocations = 0;

    public function __invoke(): array
    {
        return ['provider' => static::class, 'value' => self::$value, 'invocations' => ++$this->invocations];
    }
}

#[AsConfig]
final class AttributeConfigSnapshotSecondProvider extends AttributeConfigSnapshotFirstProvider {}

it('snapshots prepared provider order while retaining current provider values and fresh instances', function (bool $withMethod): void {
    $first = AttributeConfigSnapshotFirstProvider::class;
    $second = AttributeConfigSnapshotSecondProvider::class;
    $classes = [&$first, &$second];
    $provider = $withMethod
        ? (new AttributeConfigProvider())->withPreparedProviders($classes)
        : new AttributeConfigProvider(providerClasses: $classes);
    $first = AttributeConfigSnapshotSecondProvider::class;
    $second = AttributeConfigSnapshotFirstProvider::class;

    try {
        foreach (['initial', 'current'] as $value) {
            AttributeConfigSnapshotFirstProvider::$value = $value;
            expect(iterator_to_array($provider(), false))->toBe([
                ['provider' => AttributeConfigSnapshotFirstProvider::class, 'value' => $value, 'invocations' => 1],
                ['provider' => AttributeConfigSnapshotSecondProvider::class, 'value' => $value, 'invocations' => 1],
            ]);
        }
    } finally {
        AttributeConfigSnapshotFirstProvider::$value = 'initial';
    }
})->with(['constructor' => false, 'withPreparedProviders' => true]);
