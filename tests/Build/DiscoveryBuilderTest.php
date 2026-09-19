<?php

declare(strict_types=1);

use Componenta\App\Build\DiscoveryBuilder;
use Componenta\ClassFinder\ClassFinder;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Tokenizer\ClassInfo;

it('replays every declaration with its file and modifiers without reading source files', function (): void {
    $root = sys_get_temp_dir() . '/componenta_discovery_build_' . bin2hex(random_bytes(6));
    mkdir($root . '/src', 0700, true);
    $sourceFile = $root . '/src/Declarations.php';
    file_put_contents($sourceFile, <<<'PHP'
        <?php
        namespace DiscoveryBuildExample;
        abstract class Base {}
        final readonly class Item {}
        interface Contract {}
        trait Behavior {}
        enum Status { case Active; }
        PHP);
    $artifact = $root . '/build/classes.php';

    try {
        $builder = new DiscoveryBuilder((new ClassFinder())->find($root . '/src'), $artifact);
        expect(file_exists($artifact))->toBeFalse();
        $builder->build();
        $file = realpath($sourceFile);
        unlink($sourceFile);
        rmdir($root . '/src');

        $iterator = ClassIterator::fromMap(require $artifact);
        $rows = static function () use ($iterator): array {
            $rows = [];
            foreach ($iterator as $path => $class) {
                $rows[] = [$path, $class->fullyQualifiedName, $class->type->value,
                    $class->isAbstract, $class->isFinal, $class->isReadonly];
            }
            return $rows;
        };
        $expected = [
            [$file, 'DiscoveryBuildExample\\Base', 'class', true, false, false],
            [$file, 'DiscoveryBuildExample\\Item', 'class', false, true, true],
            [$file, 'DiscoveryBuildExample\\Contract', 'interface', false, false, false],
            [$file, 'DiscoveryBuildExample\\Behavior', 'trait', false, false, false],
            [$file, 'DiscoveryBuildExample\\Status', 'enum', false, false, false],
        ];

        expect($rows())->toBe($expected)
            ->and($rows())->toBe($expected)
            ->and($iterator->count())->toBe(5);
    } finally {
        if (is_file($sourceFile)) { unlink($sourceFile); }
        if (is_dir($root . '/src')) { rmdir($root . '/src'); }
        if (is_file($artifact)) { unlink($artifact); }
        if (is_dir($root . '/build')) { rmdir($root . '/build'); }
        rmdir($root);
    }
});

it('keeps the previous discovery map intact when reading the source fails', function (): void {
    $root = sys_get_temp_dir() . '/componenta_discovery_failure_' . bin2hex(random_bytes(6));
    mkdir($root, 0700);
    $artifact = $root . '/classes.php';
    $old = '<?php return [];';
    file_put_contents($artifact, $old);
    $failure = new DomainException('Source unavailable');
    $classes = new ClassIterator((static function () use ($failure): Generator {
        yield '/one.php' => new ClassInfo('One');
        throw $failure;
    })());

    try {
        try {
            (new DiscoveryBuilder($classes, $artifact))->build();
            test()->fail('The source failure must propagate.');
        } catch (DomainException $caught) {
            expect($caught)->toBe($failure);
        }
        expect(file_get_contents($artifact))->toBe($old)
            ->and(scandir($root))->toBe(['.', '..', 'classes.php']);
    } finally {
        unlink($artifact);
        rmdir($root);
    }
});
