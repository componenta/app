<?php

declare(strict_types=1);

namespace Componenta\App\Build;

use Componenta\App\Boot\Boot;
use Componenta\ClassFinder\ClassIteratorInterface;
use ReflectionMethod;

final readonly class BootBuilder implements ApplicationBuilderInterface, ApplicationBuildCleanerInterface
{
    public function __construct(private ClassIteratorInterface $classes, private string $file) {}

    public function clean(): void
    {
        PhpMapFile::remove($this->file);
    }

    public function build(): void
    {
        $map = [];
        foreach ($this->classes as $class) {
            $methods = [];
            foreach ($class->reflector->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getAttributes(Boot::class) !== []) { $methods[] = $method->getName(); }
            }
            $map[$class->fullyQualifiedName] = $methods;
        }
        PhpMapFile::write($this->file, $map);
    }
}
