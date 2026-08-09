<?php

declare(strict_types=1);

namespace Componenta\App\Discovery\Autowire;

use Componenta\ClassFinder\Attribute\DevOnly;
use Componenta\ClassFinder\Attribute\ListenTo;
use Componenta\ClassFinder\ClassListenerInterface;
use Componenta\DI\Attribute\Autowire;
use Componenta\DI\Compile\Autowire\AutowireEntry;
use Componenta\DI\Compile\Autowire\AutowireEntryContributorInterface;
use Componenta\Tokenizer\ClassInfo;

/** Collects user-declared compiled-autowiring roots during development discovery. */
#[DevOnly]
#[ListenTo(Autowire::class)]
final class AutowireAttributeListener implements ClassListenerInterface, AutowireEntryContributorInterface
{
    /** @var array<class-string, true> */
    private array $classes = [];

    public function handle(ClassInfo $info): void
    {
        if ($info->reflector->getAttributes(Autowire::class) === []) {
            return;
        }

        $class = $info->fullyQualifiedName;
        if ($class !== '') {
            $this->classes[$class] = true;
        }
    }

    public function entries(): iterable
    {
        $classes = array_keys($this->classes);
        sort($classes, SORT_STRING);

        foreach ($classes as $class) {
            yield new AutowireEntry($class, '#[Autowire]');
        }
    }
}
