<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

use Componenta\Config\Config;
use Componenta\Config\DefaultValue;
use Componenta\DI\Attribute\Config as ConfigAttr;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Env;
use Componenta\DI\CallableExecutorInterface;
use OutOfBoundsException;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Runs boot invocations with the same parameter semantics as DI attributes.
 */
final readonly class BootInvocationRunner implements BootInvocationRunnerInterface
{
    public function __construct(
        private ContainerInterface $container,
        private CallableExecutorInterface $executor,
    ) {
    }

    public function run(iterable $invocations): void
    {
        $ordered = is_array($invocations) ? $invocations : iterator_to_array($invocations, preserve_keys: false);

        usort(
            $ordered,
            static fn (BootInvocation $a, BootInvocation $b): int => $b->priority <=> $a->priority,
        );

        foreach ($ordered as $invocation) {
            $this->executor->call(
                [$invocation->class, $invocation->method],
                $this->resolveParams($invocation->params),
            );
        }
    }

    /**
     * @param array<string|int, mixed> $params
     *
     * @return array<string|int, mixed>
     */
    private function resolveParams(array $params): array
    {
        foreach ($params as $name => $value) {
            $params[$name] = match (true) {
                $value instanceof EntryId => $this->container->get($value->value),
                $value instanceof ConfigAttr => $this->resolveConfigParam($value, (string) $name),
                $value instanceof Env => $this->resolveEnvParam($value, (string) $name),
                default => $value,
            };
        }

        return $params;
    }

    private function resolveConfigParam(ConfigAttr $attribute, string $name): mixed
    {
        $config = $this->container->get(Config::class);
        if (!$config instanceof Config) {
            throw new RuntimeException(sprintf('Container entry %s must be %s.', Config::class, Config::class));
        }

        return $config->get($attribute->path ?? $name, $attribute->default);
    }

    private function resolveEnvParam(Env $attribute, string $name): mixed
    {
        $config = $this->container->get(Config::class);
        if (!$config instanceof Config) {
            throw new RuntimeException(sprintf('Container entry %s must be %s.', Config::class, Config::class));
        }

        $environment = $config->environment;
        $envName = $attribute->name ?? strtoupper(
            preg_replace('/([a-z])([A-Z])/', '$1_$2', $name) ?? $name,
        );

        if (!$environment->has($envName)) {
            if ($attribute->default !== DefaultValue::None) {
                return $attribute->default;
            }

            throw new OutOfBoundsException("Environment variable '$envName' is not defined");
        }

        return $environment->get($envName);
    }
}
