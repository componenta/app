<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

use Componenta\Config\Config;
use Componenta\Config\DefaultValue;
use Componenta\DI\Attribute\Config as ConfigAttr;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Env;
use Componenta\DI\CallableExecutorInterface;
use Componenta\Reflection\Reflection;
use LogicException;
use OutOfBoundsException;
use Psr\Container\ContainerInterface;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

/** Resolves explicit boot metadata before delegating invocation to DI. */
final readonly class BootInvocationRunner implements BootInvocationRunnerInterface
{
    public function __construct(
        private ContainerInterface $container,
        private CallableExecutorInterface $executor,
    ) {}

    public function run(iterable $invocations): void
    {
        $ordered = is_array($invocations) ? $invocations : iterator_to_array($invocations, preserve_keys: false);

        usort(
            $ordered,
            static fn (BootInvocation $a, BootInvocation $b): int => $b->priority <=> $a->priority,
        );

        foreach ($ordered as $invocation) {
            $callable = [$invocation->class, $invocation->method];
            $params = $invocation->params;
            $targets = null;

            foreach ($params as $key => $value) {
                if ($value instanceof EntryId) {
                    $params[$key] = $this->container->get($value->value);
                    continue;
                }
                if (!$value instanceof Env && !$value instanceof ConfigAttr) {
                    continue;
                }

                if ($targets === null) {
                    // Resolve the actual callable once, including DI-provided
                    // instance methods. Plain values need no extra reflection.
                    $callable = $this->executor->resolve($callable);
                    $targets = Reflection::callable($callable)->getParameters();
                }
                $parameter = self::target($targets, $key);
                $params[$key] = $value instanceof Env
                    ? $this->resolveEnvParam($value, $parameter, is_string($key))
                    : $this->config()->get($value->path ?? $parameter->getName(), $value->default);
            }

            $this->executor->call($callable, $params);
        }
    }

    /** @param list<ReflectionParameter> $parameters */
    private static function target(array $parameters, string|int $key): ReflectionParameter
    {
        foreach ($parameters as $parameter) {
            if ($key === $parameter->getName() || $key === $parameter->getPosition()) {
                return $parameter;
            }
            if ($parameter->isVariadic() && is_int($key) && $key >= $parameter->getPosition()) {
                return $parameter;
            }
        }

        throw new LogicException(sprintf('No boot parameter corresponds to "%s".', (string) $key));
    }

    private function config(): Config
    {
        $config = $this->container->get(Config::class);
        if (!$config instanceof Config) {
            throw new RuntimeException(sprintf('Container entry %s must be %s.', Config::class, Config::class));
        }
        return $config;
    }

    private function resolveEnvParam(Env $attribute, ReflectionParameter $parameter, bool $named): mixed
    {
        $environment = $this->config()->environment;
        $name = $parameter->getName();
        $envName = $attribute->name ?? strtoupper(
            preg_replace('/([a-z])([A-Z])/', '$1_$2', $name) ?? $name,
        );

        if (!$environment->has($envName)) {
            if ($attribute->default !== DefaultValue::None) {
                return $attribute->default;
            }

            throw new OutOfBoundsException("Environment variable '$envName' is not defined");
        }

        $type = $parameter->getType();
        // A named variadic entry is a collection; positional entries are
        // individual arguments, matching DI's explicit-parameter contract.
        $typeName = $parameter->isVariadic() && $named
            ? 'array'
            : ($type instanceof ReflectionNamedType ? $type->getName() : null);

        return match ($typeName) {
            'string' => $environment->string($envName),
            'int' => $environment->int($envName),
            'float' => $environment->float($envName),
            'bool' => $environment->bool($envName),
            'array' => $environment->array($envName),
            default => $environment->get($envName),
        };
    }
}
