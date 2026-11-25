<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

use Componenta\ClassFinder\Attribute\ListenTo;
use Componenta\ClassFinder\Exception\ListenerAlreadyFinalizedException;
use Componenta\ClassFinder\FinalizableListenerInterface;
use Componenta\ClassFinder\FinalizationStateInterface;
use Componenta\Tokenizer\ClassInfo;
use ReflectionClass;
use ReflectionMethod;

#[ListenTo(Boot::class, deepSearch: true)]
final class BootMethodInvocation implements
    FinalizableListenerInterface,
    FinalizationStateInterface
{
    /** @var list<BootInvocation> */
    private array $invocations = [];
    private bool $isFinalized = false;
    /** @var array<class-string, list<non-empty-string>>|null */
    private readonly ?array $methodMap;

    public bool $finalized {
        get => $this->isFinalized;
    }

    public function __construct(
        private readonly BootInvocationRunnerInterface $runner,
        ?array $methodMap = null,
    ) {
        $this->methodMap = self::validMap($methodMap) ? $methodMap : null;
    }

    public function handle(ClassInfo $info): void
    {
        if ($this->isFinalized) {
            throw ListenerAlreadyFinalizedException::forListener(self::class);
        }

        // A prepared entry with no boot methods needs neither autoload nor reflection.
        if (($this->methodMap[$info->fullyQualifiedName] ?? null) === []) {
            return;
        }

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

    /**
     * @param ReflectionClass<object> $reflection
     * @return list<BootInvocation>
     */
    private function extract(ReflectionClass $reflection): array
    {
        $methods = [];

        foreach ($this->methods($reflection) as $method) {
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

    /** @param ReflectionClass<object> $reflection @return list<ReflectionMethod> */
    private function methods(ReflectionClass $reflection): array
    {
        $names = $this->methodMap[$reflection->getName()] ?? null;
        if ($names !== null) {
            $methods = [];
            foreach ($names as $name) {
                if (!$reflection->hasMethod($name) || !$reflection->getMethod($name)->isPublic()) {
                    return $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
                }
                $methods[] = $reflection->getMethod($name);
            }
            return $methods;
        }
        return $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
    }

    private static function validMap(?array $map): bool
    {
        if ($map === null) { return false; }
        foreach ($map as $class => $methods) {
            if (!is_string($class) || $class === '' || !is_array($methods) || !array_is_list($methods)) { return false; }
            $seen = [];
            foreach ($methods as $method) {
                if (!is_string($method) || $method === '' || isset($seen[strtolower($method)])) { return false; }
                $seen[strtolower($method)] = true;
            }
        }
        return true;
    }
}
