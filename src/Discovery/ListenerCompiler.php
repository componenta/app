<?php

declare(strict_types=1);

namespace Componenta\App\Discovery;

use Componenta\ClassFinder\Attribute\ListenTo;
use Componenta\ClassFinder\ClassListenerInterface;
use Componenta\ClassFinder\ClassListenerProviderInterface;
use Componenta\Tokenizer\ClassInfo;
use ReflectionClass;

/**
 * Builds the `{classes, targets}` snapshot that {@see ListenerRestorer}
 * replays at runtime.
 *
 * Pure transformation: `compile()` reads each listener's `#[ListenTo]`
 * attributes (no listener state is touched) and filters the supplied
 * classes into per-listener index arrays. The output is stable across
 * calls for the same `(classes, listeners)` inputs. Empty filtered
 * listener results are stored in one compact `empty_targets` list instead
 * of as empty map values. Listeners with no `#[ListenTo]` on them produce
 * no entry - `ListenerRestorer` then falls back to feeding them the full
 * class list at runtime.
 */
final readonly class ListenerCompiler
{
    public function __construct(
        private ClassListenerProviderInterface $listenerProvider,
    ) {}

    /**
     * @param iterable<ClassInfo> $classes
     *
     * @return array{
     *     classes?: list<class-string>,
     *     targets?: array<class-string, list<int>>,
     *     empty_targets?: list<class-string>
     * }
     */
    public function compile(iterable $classes): array
    {
        /** @var array<class-string, ClassInfo> $classInfos */
        $classInfos = [];

        foreach ($classes as $info) {
            $classInfos[$info->fullyQualifiedName] ??= $info;
        }

        /** @var list<class-string> $classNames */
        $classNames = array_keys($classInfos);
        $classInfos = array_values($classInfos);
        $index   = array_flip($classNames);
        $targets = [];
        /** @var list<class-string> $emptyTargets */
        $emptyTargets = [];

        foreach ($this->listenerProvider->getClassListeners() as $listener) {
            $listenTos = $this->readListenTos($listener);

            if ($listenTos === []) {
                continue;
            }

            $indices = [];

            foreach ($classInfos as $info) {
                if ($this->matchesAnyTarget($info, $listenTos)) {
                    $indices[] = $index[$info->fullyQualifiedName];
                }
            }

            if ($indices === []) {
                $emptyTargets[] = $listener::class;
            } else {
                $targets[$listener::class] = $indices;
            }
        }

        $cache = [];

        if ($classNames !== []) {
            $cache['classes'] = $classNames;
        }

        if ($targets !== []) {
            $cache['targets'] = $targets;
        }

        if ($emptyTargets !== []) {
            $cache['empty_targets'] = $emptyTargets;
        }

        return $cache;
    }

    /**
     * @return list<ListenTo>
     */
    private function readListenTos(ClassListenerInterface $listener): array
    {
        $attributes = (new ReflectionClass($listener))->getAttributes(ListenTo::class);

        return array_map(
            static fn ($attr): ListenTo => $attr->newInstance(),
            $attributes,
        );
    }

    /**
     * @param list<ListenTo> $targets
     */
    private function matchesAnyTarget(ClassInfo $classInfo, array $targets): bool
    {
        foreach ($targets as $target) {
            if ($this->matchesTarget($classInfo, $target)) {
                return true;
            }
        }

        return false;
    }

    private function matchesTarget(ClassInfo $classInfo, ListenTo $target): bool
    {
        $reflector = $classInfo->reflector;

        if (array_any(
            $reflector->getAttributes(),
            static fn ($attr): bool => $attr->getName() === $target->attribute,
        )) {
            return true;
        }

        if (!$target->deepSearch) {
            return false;
        }

        if (array_any(
            $reflector->getMethods(),
            static fn ($method): bool => array_any(
                $method->getAttributes(),
                static fn ($attr): bool => $attr->getName() === $target->attribute,
            ),
        )) {
            return true;
        }

        if (array_any(
            $reflector->getProperties(),
            static fn ($property): bool => array_any(
                $property->getAttributes(),
                static fn ($attr): bool => $attr->getName() === $target->attribute,
            ),
        )) {
            return true;
        }

        return array_any(
            $reflector->getReflectionConstants(),
            static fn ($constant): bool => array_any(
                $constant->getAttributes(),
                static fn ($attr): bool => $attr->getName() === $target->attribute,
            ),
        );
    }
}
