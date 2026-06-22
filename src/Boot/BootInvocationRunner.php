<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

use Componenta\Config\Config;
use Componenta\Config\DefaultValue;
use Componenta\DI\Attribute\Config as ConfigAttr;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Env;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\Resolver\ConfigValueExtractor;
use Componenta\DI\Resolver\EnvNameNormalizer;
use OutOfBoundsException;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Runs boot invocations with the same parameter semantics as DI attributes.
 */
final readonly class BootInvocationRunner implements BootInvocationRunnerInterface
{
    private ConfigValueExtractor $configExtractor;

    public function __construct(
        private ContainerInterface $container,
        private CallableExecutorInterface $executor,
    ) {
        $this->configExtractor = new ConfigValueExtractor();
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
        return $this->configExtractor->extract(
            $this->container->get(Config::class),
            $attribute,
            $name,
        );
    }

    private function resolveEnvParam(Env $attribute, string $name): mixed
    {
        $config = $this->container->get(Config::class);
        $environment = $config->environment;

        if ($environment === null) {
            if ($attribute->default !== DefaultValue::None) {
                return $attribute->default;
            }

            throw new RuntimeException('Environment not available in Config');
        }

        $envName = $attribute->name ?? EnvNameNormalizer::toEnvName($name);

        if (!$environment->has($envName)) {
            if ($attribute->default !== DefaultValue::None) {
                return $attribute->default;
            }

            throw new OutOfBoundsException("Environment variable '$envName' is not defined");
        }

        return $environment->get($envName);
    }
}
