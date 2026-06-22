<?php

declare(strict_types=1);

use Componenta\App\Discovery\Compile\DiPlanBuilder;
use Componenta\Config\Config;
use Componenta\DI\Compile\AttributeMatcherInterface;
use Componenta\DI\Compile\PlanCompiler;
use Componenta\DI\ConfigKey;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Property\PropertiesResolver;

final class DiPlanBuilderFlagMatcher implements AttributeMatcherInterface, ParameterResolverInterface
{
    public function planKind(): string
    {
        return 'componenta.test.claimed';
    }

    public function claimTarget(ReflectionParameter|ReflectionProperty $target): ?string
    {
        return $target instanceof ReflectionParameter && $target->getName() === 'claimed'
            ? $this->planKind()
            : null;
    }

    public function resolveParameter(
        ReflectionParameter $parameter,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array {
        return null;
    }
}

final readonly class DiPlanBuilderFlagTarget
{
    public function __construct(
        public string $claimed,
        public string $unclaimed,
    ) {}
}

function diPlanBuilderForModeTest(Config $config): DiPlanBuilder
{
    return new DiPlanBuilder(
        new ParametersResolver(new DiPlanBuilderFlagMatcher()),
        new PropertiesResolver(),
        $config,
    );
}

describe('Discovery compile DI plan builder', function () {
    it('uses sparse DI plans from dependency config mode', function () {
        $config = new Config([
            ConfigKey::DEPENDENCIES => [
                PlanCompiler::MODE_CONFIG_KEY => PlanCompiler::MODE_SPARSE,
            ],
        ]);

        $plans = diPlanBuilderForModeTest($config)->compile([DiPlanBuilderFlagTarget::class]);

        expect($plans['param'][DiPlanBuilderFlagTarget::class]['__construct'])->toBe([
            0 => 'componenta.test.claimed',
        ]);
    });

    it('keeps complete dependency config mode', function () {
        $config = new Config([
            ConfigKey::DEPENDENCIES => [
                PlanCompiler::MODE_CONFIG_KEY => PlanCompiler::MODE_COMPLETE,
            ],
        ]);

        $plans = diPlanBuilderForModeTest($config)->compile([DiPlanBuilderFlagTarget::class]);

        expect($plans['param'])->not->toHaveKey(DiPlanBuilderFlagTarget::class);
    });
});
