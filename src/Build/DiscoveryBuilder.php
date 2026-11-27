<?php

declare(strict_types=1);

namespace Componenta\App\Build;

use Componenta\App\ConfigKey;
use Componenta\ClassFinder\ClassIteratorInterface;

final readonly class DiscoveryBuilder implements ApplicationBuilderInterface
{
    public function __construct(
        private ClassIteratorInterface $classes,
    ) {
    }

    public function build(BuildContext $build): void
    {
        $map = [];
        foreach ($this->classes as $file => $class) {
            $map[] = [
                'file' => $file,
                'name' => $class->fullyQualifiedName,
                'type' => $class->type->value,
                'abstract' => $class->isAbstract,
                'final' => $class->isFinal,
                'readonly' => $class->isReadonly,
            ];
        }

        $build->write(ConfigKey::DISCOVERY_ARTIFACT, $map);
    }
}
