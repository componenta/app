<?php

declare(strict_types=1);

namespace Componenta\App\Discovery\Compile;

use Componenta\Config\Config;
use Componenta\DI\Compile\AttributeMatcherInterface;
use Componenta\DI\Compile\PlanCompiler;
use Componenta\DI\Compile\PlanDispatcher;
use Componenta\DI\ConfigKey as DiConfigKey;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Property\PropertiesResolver;

/**
 * Builds {@see PlanCompiler}'s output from the live resolver chains.
 *
 * The compiler itself (in `Componenta\DI`) is a pure transformer - given a
 * list of classes and a set of {@see AttributeMatcherInterface}
 * matchers, it emits the plan map that `ParametersResolver` and
 * `PropertiesResolver` consume to skip their chain walks at runtime.
 *
 * This bridge captures the "pull matchers off the runtime resolvers"
 * idiom that otherwise duplicates between the CLI compile command and
 * the dev-time cache path. The live chains include any user-registered
 * resolvers (via `addParameterResolver()` / `setPropertyResolver()`) -
 * compile-only resolvers included.
 */
final readonly class DiPlanBuilder
{
    public function __construct(
        private ParametersResolver $parametersResolver,
        private PropertiesResolver $propertiesResolver,
        private ?Config $config = null,
    ) {}

    /**
     * @param iterable<class-string> $classes
     *
     * @return array{param: array<class-string, array<string, array<int, string>>>, prop: array<class-string, array<string, string>>}
     */
    public function compile(iterable $classes): array
    {
        return new PlanCompiler(
            $this->extractMatchers($this->parametersResolver->resolvers),
            $this->extractMatchers($this->propertiesResolver->resolvers),
            $this->planMode(),
        )->compile($classes);
    }

    /**
     * @return array{param: array<string, class-string>, prop: array<string, class-string>}
     */
    public function dispatcherMap(): array
    {
        return PlanDispatcher::kindMap(
            $this->parametersResolver->resolvers,
            $this->propertiesResolver->resolvers,
        );
    }

    private function planMode(): string
    {
        $dependencies = $this->config?->get(DiConfigKey::DEPENDENCIES, default: []);

        if (!is_array($dependencies)) {
            $mode = PlanCompiler::MODE_SPARSE;
        } else {
            $mode = $dependencies[PlanCompiler::MODE_CONFIG_KEY] ?? PlanCompiler::MODE_SPARSE;
        }

        return is_string($mode) ? $mode : PlanCompiler::MODE_SPARSE;
    }

    /**
     * @return list<AttributeMatcherInterface>
     */
    private function extractMatchers(iterable $chain): array
    {
        $matchers = [];

        foreach ($chain as $resolver) {
            if ($resolver instanceof AttributeMatcherInterface) {
                $matchers[] = $resolver;
            }
        }

        return $matchers;
    }
}
