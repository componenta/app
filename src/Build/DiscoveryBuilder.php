<?php

declare(strict_types=1);

namespace Componenta\App\Build;

use Componenta\App\Internal\BuildArtifact;
use Componenta\ClassFinder\ClassIteratorInterface;

final readonly class DiscoveryBuilder implements ApplicationBuilderInterface, ApplicationBuildCleanerInterface
{
    public function __construct(
        private ClassIteratorInterface $classes,
        private string $file,
        private ?string $generation = null,
    ) {
    }

    public function clean(): void
    {
        PhpMapFile::remove($this->file);
    }

    public function build(): void
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

        PhpMapFile::write($this->file, BuildArtifact::wrap(
            $this->generation ?? BuildArtifact::newGeneration(),
            $map,
        ));
    }
}
