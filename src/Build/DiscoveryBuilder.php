<?php

declare(strict_types=1);

namespace Componenta\App\Build;

use Componenta\App\Internal\AtomicFileWriter;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\VarExport\ArrayExporter;
use Componenta\VarExport\ScalarExporter;
use Componenta\VarExport\VarExport;

final readonly class DiscoveryBuilder implements ApplicationBuilderInterface, ApplicationBuildCleanerInterface
{
    public function __construct(
        private ClassIteratorInterface $classes,
        private string $file,
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

        $expression = (new VarExport(new ScalarExporter(), new ArrayExporter()))->export($map);
        AtomicFileWriter::write($this->file, "<?php\ndeclare(strict_types=1);\nreturn " . $expression . ";\n");
    }
}
