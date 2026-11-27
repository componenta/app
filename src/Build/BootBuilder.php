<?php

declare(strict_types=1);

namespace Componenta\App\Build;

use Componenta\App\Boot\Boot;
use Componenta\App\ConfigKey;
use Componenta\ClassFinder\ClassIteratorInterface;
use ReflectionMethod;

final readonly class BootBuilder implements ApplicationBuilderInterface
{
    public function __construct(
        private ClassIteratorInterface $classes,
    ) {}

    public function build(BuildContext $build): void
    {
        $map = [];
        foreach ($this->classes as $class) {
            $methods = [];
            foreach ($class->reflector->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getAttributes(Boot::class) !== []) { $methods[] = $method->getName(); }
            }
            $map[$class->fullyQualifiedName] = $methods;
        }
        $build->write(ConfigKey::BOOT_ARTIFACT, $map);
    }
}
