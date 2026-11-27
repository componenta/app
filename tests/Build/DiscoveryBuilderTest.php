<?php

declare(strict_types=1);

use Componenta\App\Build\DiscoveryBuilder;
use Componenta\App\Build\BuildStore;
use Componenta\App\ConfigKey;
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
    $store = new BuildStore($root . '/build');

    try {
        $builder = new DiscoveryBuilder((new ClassFinder())->find($root . '/src'));
        expect($store->load())->toBeNull();
        $context = $store->begin();
        $builder->build($context);
        $prepared = $store->publish($context);
        $file = realpath($sourceFile);
        unlink($sourceFile);
        rmdir($root . '/src');

        $iterator = ClassIterator::fromMap($prepared->read(ConfigKey::DISCOVERY_ARTIFACT));
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
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($root);
    }
});

it('keeps the previous discovery map intact when reading the source fails', function (): void {
    $root = sys_get_temp_dir() . '/componenta_discovery_failure_' . bin2hex(random_bytes(6));
    mkdir($root, 0700);
    $store = new BuildStore($root . '/build');
    $initial = $store->begin();
    $initial->write(ConfigKey::DISCOVERY_ARTIFACT, ['old']);
    $old = $store->publish($initial);
    $failure = new DomainException('Source unavailable');
    $classes = new ClassIterator((static function () use ($failure): Generator {
        yield '/one.php' => new ClassInfo('One');
        throw $failure;
    })());

    try {
        try {
            (new DiscoveryBuilder($classes))->build($store->begin());
            test()->fail('The source failure must propagate.');
        } catch (DomainException $caught) {
            expect($caught)->toBe($failure);
        }
        expect($store->load()->generation)->toBe($old->generation)
            ->and($store->load()->read(ConfigKey::DISCOVERY_ARTIFACT))->toBe(['old']);
    } finally {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($root);
    }
});
