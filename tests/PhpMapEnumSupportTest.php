<?php

declare(strict_types=1);

use Componenta\App\Build\PhpMapFile;

enum PhpMapEnumFixture { case Test; }

it('preserves enum cases in generation-bound trusted maps', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'app-map-');
    if ($file === false) {
        throw new RuntimeException('Cannot create temporary map.');
    }
    $generation = str_repeat('a', 32);
    try {
        $payload = ['nested' => ['enum' => PhpMapEnumFixture::Test]];
        PhpMapFile::write($file, $payload, $generation);
        expect(PhpMapFile::read($file, $generation))->toBe($payload)
            ->and(PhpMapFile::read($file, str_repeat('b', 32)))->toBeNull();
        expect(fn () => PhpMapFile::write($file, ['object' => new stdClass()], $generation))
            ->toThrow(Componenta\VarExport\Exception\ExceptionInterface::class);
        expect(PhpMapFile::read($file, $generation))->toBe($payload);
    } finally {
        PhpMapFile::remove($file);
    }
});
