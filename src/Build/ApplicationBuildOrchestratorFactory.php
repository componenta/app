<?php

declare(strict_types=1);

namespace Componenta\App\Build;

use Componenta\App\ConfigKey;
use Componenta\Config\ContainerValue;
use RuntimeException;

final class ApplicationBuildOrchestratorFactory
{
    public function __invoke(ContainerValue $container): ApplicationBuildOrchestrator
    {
        $ids = $container->config->get(ConfigKey::BUILDERS, []);

        if (!is_array($ids) || !array_is_list($ids)) {
            throw new RuntimeException(sprintf(
                '%s must be a list of non-empty service IDs; got %s.',
                ConfigKey::BUILDERS,
                get_debug_type($ids),
            ));
        }

        $seen = [];
        foreach ($ids as $index => $id) {
            if (!is_string($id) || trim($id) === '') {
                throw new RuntimeException(sprintf(
                    '%s[%d] must be a non-empty service ID; got %s.',
                    ConfigKey::BUILDERS,
                    $index,
                    get_debug_type($id),
                ));
            }

            if (isset($seen[$id])) {
                throw new RuntimeException(sprintf(
                    '%s contains duplicate builder service ID "%s" at index %d.',
                    ConfigKey::BUILDERS,
                    $id,
                    $index,
                ));
            }

            $seen[$id] = true;
        }

        $builders = [];
        foreach ($ids as $id) {
            $builder = $container->get($id);

            if (!$builder instanceof ApplicationBuilderInterface) {
                throw new RuntimeException(sprintf(
                    '%s service "%s" must implement %s; got %s.',
                    ConfigKey::BUILDERS,
                    $id,
                    ApplicationBuilderInterface::class,
                    get_debug_type($builder),
                ));
            }

            $builders[] = $builder;
        }

        return new ApplicationBuildOrchestrator($builders);
    }
}