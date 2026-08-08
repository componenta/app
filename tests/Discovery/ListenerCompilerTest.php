<?php

declare(strict_types=1);

use Componenta\App\Discovery\ListenerCompiler;
use Componenta\ClassFinder\Attribute\ListenTo;
use Componenta\ClassFinder\ClassListenerInterface;
use Componenta\ClassFinder\ClassListenerProviderInterface;
use Componenta\Tokenizer\ClassInfo;

#[Attribute(Attribute::TARGET_CLASS)]
final class ListenerCompilerMatchedAttribute {}

#[Attribute(Attribute::TARGET_CLASS)]
final class ListenerCompilerMissingAttribute {}

#[ListenerCompilerMatchedAttribute]
final class ListenerCompilerMatchedClass {}

final class ListenerCompilerOtherClass {}

#[ListenTo(ListenerCompilerMatchedAttribute::class)]
final class ListenerCompilerMatchedListener implements ClassListenerInterface
{
    public function handle(ClassInfo $info): void {}
}

#[ListenTo(ListenerCompilerMissingAttribute::class)]
final class ListenerCompilerEmptyListener implements ClassListenerInterface
{
    public function handle(ClassInfo $info): void {}
}

final readonly class ListenerCompilerProvider implements ClassListenerProviderInterface
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

describe('Discovery listener compiler', function (): void {
    it('deduplicates classes and compacts empty filtered listener results', function (): void {
        $compiler = new ListenerCompiler(new ListenerCompilerProvider([
            new ListenerCompilerMatchedListener(),
            new ListenerCompilerEmptyListener(),
        ]));

        $cache = $compiler->compile([
            new ClassInfo(ListenerCompilerMatchedClass::class),
            new ClassInfo(ListenerCompilerMatchedClass::class),
            new ClassInfo(ListenerCompilerOtherClass::class),
        ]);

        expect($cache)->toBe([
            'classes' => [
                ListenerCompilerMatchedClass::class,
                ListenerCompilerOtherClass::class,
            ],
            'targets' => [
                ListenerCompilerMatchedListener::class => [0],
            ],
            'empty_targets' => [
                ListenerCompilerEmptyListener::class,
            ],
        ]);
    });

    it('returns no cache sections when classes and listeners are empty', function (): void {
        $compiler = new ListenerCompiler(new ListenerCompilerProvider([]));

        expect($compiler->compile([]))->toBe([]);
    });
});
