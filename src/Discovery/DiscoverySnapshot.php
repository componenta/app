<?php

declare(strict_types=1);

namespace Componenta\App\Discovery;

use Componenta\ClassFinder\ClassIterator;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Stdlib\PathResolverInterface;
use Componenta\Tokenizer\ClassInfo;
use Componenta\Tokenizer\DeclarationType;
use Generator;
use RuntimeException;

final class DiscoverySnapshot
{
    private function __construct()
    {
    }

    /**
     * @return list<array{
     *     file: string,
     *     fqcn: non-empty-string,
     *     type: string,
     *     isAbstract: bool,
     *     isFinal: bool,
     *     isReadonly: bool
     * }>
     */
    public static function capture(
        ClassIteratorInterface $classes,
        PathResolverInterface $paths,
    ): array {
        $snapshot = [];
        $root = rtrim(str_replace('\\', '/', $paths->baseDir), '/');

        foreach ($classes as $filename => $class) {
            if ($class->fullyQualifiedName === '') {
                throw new RuntimeException('Discovered class name cannot be empty.');
            }

            $file = str_replace('\\', '/', (string) $filename);
            if ($file === $root || str_starts_with($file, $root . '/')) {
                $file = ltrim(substr($file, strlen($root)), '/');
            }

            $snapshot[] = [
                'file' => $file,
                'fqcn' => $class->fullyQualifiedName,
                'type' => $class->type->value,
                'isAbstract' => $class->isAbstract,
                'isFinal' => $class->isFinal,
                'isReadonly' => $class->isReadonly,
            ];
        }

        return $snapshot;
    }

    /** @param array<array-key, mixed> $snapshot */
    public static function materialize(
        array $snapshot,
        PathResolverInterface $paths,
    ): ClassIteratorInterface {
        $normalized = [];

        foreach ($snapshot as $index => $row) {
            if (!is_array($row)) {
                throw new RuntimeException(sprintf('Discovery row %s must be an array.', $index));
            }

            $file = $row['file'] ?? null;
            $fqcn = $row['fqcn'] ?? null;
            $type = $row['type'] ?? null;
            $isAbstract = $row['isAbstract'] ?? null;
            $isFinal = $row['isFinal'] ?? null;
            $isReadonly = $row['isReadonly'] ?? null;

            if (!is_string($file)
                || !is_string($fqcn)
                || $fqcn === ''
                || !is_string($type)
                || !is_bool($isAbstract)
                || !is_bool($isFinal)
                || !is_bool($isReadonly)
            ) {
                throw new RuntimeException(sprintf('Discovery row %s has an invalid shape.', $index));
            }

            try {
                $declarationType = DeclarationType::from($type);
            } catch (\ValueError $e) {
                throw new RuntimeException(sprintf(
                    'Discovery row %s has unsupported declaration type "%s".',
                    $index,
                    $type,
                ), previous: $e);
            }

            $normalized[] = [
                'file' => $file,
                'fqcn' => $fqcn,
                'type' => $declarationType,
                'isAbstract' => $isAbstract,
                'isFinal' => $isFinal,
                'isReadonly' => $isReadonly,
            ];
        }

        return new ClassIterator(self::replay($normalized, $paths));
    }

    /**
     * @param list<array{
     *     file: string,
     *     fqcn: non-empty-string,
     *     type: DeclarationType,
     *     isAbstract: bool,
     *     isFinal: bool,
     *     isReadonly: bool
     * }> $snapshot
     * @return Generator<string, ClassInfo>
     */
    private static function replay(array $snapshot, PathResolverInterface $paths): Generator
    {
        foreach ($snapshot as $row) {
            yield $paths->resolve($row['file']) => new ClassInfo(
                fullyQualifiedName: $row['fqcn'],
                type: $row['type'],
                isAbstract: $row['isAbstract'],
                isFinal: $row['isFinal'],
                isReadonly: $row['isReadonly'],
            );
        }
    }
}
