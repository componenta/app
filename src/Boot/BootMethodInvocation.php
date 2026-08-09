<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

use Componenta\ClassFinder\Attribute\DevOnly;
use Componenta\ClassFinder\Attribute\ListenTo;
use Componenta\ClassFinder\Exception\ListenerAlreadyFinalizedException;
use Componenta\ClassFinder\FinalizableListenerInterface;
use Componenta\ClassFinder\FinalizationStateInterface;
use Componenta\DI\Compile\Autowire\AutowireEntry;
use Componenta\DI\Compile\Autowire\AutowireEntryContributorInterface;
use Componenta\Tokenizer\ClassInfo;
use ReflectionClass;
use ReflectionMethod;

#[ListenTo(Boot::class, deepSearch: true)]
#[DevOnly]
final class BootMethodInvocation implements
    FinalizableListenerInterface,
    FinalizationStateInterface,
    BootInvocationProviderInterface,
    AutowireEntryContributorInterface
{
    /** @var list<BootInvocation> */
    private array $invocations = [];
    private bool $isFinalized = false;

    public bool $finalized {
        get => $this->isFinalized;
    }

    public array $bootInvocations {
        get => $this->invocations;
    }

    public function __construct(
        private readonly BootInvocationRunnerInterface $runner,
    ) {}

    public function handle(ClassInfo $info): void
    {
        $extracted = $this->extract($info->reflector);

        foreach ($extracted as $invocation) {
            $this->invocations[] = $invocation;
        }
    }

    public function finalize(): void
    {
        if ($this->isFinalized) {
            throw ListenerAlreadyFinalizedException::forListener(self::class);
        }

        $this->isFinalized = true;
        $this->runner->run($this->invocations);
    }

    public function entries(): iterable
    {
        $classes = [];
        foreach ($this->invocations as $invocation) {
            $classes[$invocation->class] = true;
        }
        ksort($classes);

        foreach (array_keys($classes) as $class) {
            yield new AutowireEntry($class, '#[Boot]');
        }
    }

    /**
     * @return list<BootInvocation>
     */
    private function extract(ReflectionClass $reflection): array
    {
        $methods = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(Boot::class);

            foreach ($attributes as $attribute) {
                $boot = $attribute->newInstance();

                $methods[] = new BootInvocation(
                    class: $reflection->getName(),
                    method: $method->getName(),
                    priority: $boot->priority,
                    params: $boot->params,
                );
            }
        }

        return $methods;
    }
}
