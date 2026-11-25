<?php

declare(strict_types=1);

use Componenta\App\Build\PhpMapFile;

it('publishes a readable map without group or other write permission despite a permissive umask', function (): void {
    if (DIRECTORY_SEPARATOR !== '/') {
        test()->markTestSkipped('POSIX permission semantics are required.');
    }
    $root = sys_get_temp_dir() . '/componenta-map-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    $file = $root . '/map.php';
    $previous = umask(0);
    try {
        PhpMapFile::write($file, ['key' => 'value']);
        clearstatcache(true, $file);
        expect(fileperms($file) & 0o022)->toBe(0)
            ->and(PhpMapFile::read($file))->toBe(['key' => 'value']);
        chmod($file, 0666);
        expect(PhpMapFile::read($file))->toBeNull();
    } finally {
        umask($previous);
        if (is_file($file)) { unlink($file); }
        rmdir($root);
    }
});

it('does not execute a PHP map reached through a symbolic link', function (): void {
    if (DIRECTORY_SEPARATOR !== '/') {
        test()->markTestSkipped('POSIX symbolic link semantics are required.');
    }
    $root = sys_get_temp_dir() . '/componenta-map-link-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    $file = $root . '/actual.php';
    $link = $root . '/map.php';
    $marker = $root . '/executed';
    file_put_contents($file, '<?php file_put_contents(' . var_export($marker, true) . ', "yes"); return [];');
    symlink($file, $link);
    try {
        expect(PhpMapFile::read($link))->toBeNull()->and(file_exists($marker))->toBeFalse();
        PhpMapFile::remove($link);
        expect(is_file($file))->toBeTrue();
    } finally {
        foreach ([$link, $marker, $file] as $path) {
            if (is_link($path) || is_file($path)) { unlink($path); }
        }
        rmdir($root);
    }
});


it('discards accidental output from unusable PHP maps without changing the caller buffer', function (string $contents): void {
    $root = sys_get_temp_dir() . '/componenta-map-output-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    $file = $root . '/map.php';
    file_put_contents($file, $contents);
    $level = ob_get_level();
    ob_start();
    try {
        echo 'before';
        $map = PhpMapFile::read($file);
        echo 'after';
        expect($map)->toBeNull()
            ->and(ob_get_level())->toBe($level + 1)
            ->and(ob_get_contents())->toBe('beforeafter');
    } finally {
        while (ob_get_level() > $level) { ob_end_clean(); }
        unlink($file);
        rmdir($root);
    }
})->with([
    'plain text' => 'not a PHP map',
    'output and array' => '<?php echo "unexpected"; return [];',
    'output and failure' => '<?php echo "unexpected"; throw new LogicException("bad map");',
]);

it('loads a versioned map only when its generation matches the requested snapshot', function (): void {
    $root = sys_get_temp_dir() . '/componenta-map-generation-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    $file = $root . '/map.php';
    $generation = str_repeat('a', 32);
    try {
        PhpMapFile::write($file, ['Example' => false], $generation);
        expect(PhpMapFile::read($file, $generation))->toBe(['Example' => false])
            ->and(PhpMapFile::read($file, str_repeat('b', 32)))->toBeNull();
        PhpMapFile::write($file, ['Example' => false]);
        expect(PhpMapFile::read($file, $generation))->toBeNull()
            ->and(PhpMapFile::read($file))->toBe(['Example' => false]);
    } finally {
        PhpMapFile::remove($file);
        rmdir($root);
    }
});

it('preserves unrelated files and removes owned temporary files when publication fails', function (): void {
    $root = sys_get_temp_dir() . '/componenta-map-failed-publication-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    $file = $root . '/map.php';
    mkdir($file, 0700);
    $unrelated = $root . '/keep.txt';
    file_put_contents($unrelated, 'keep');
    try {
        expect(fn () => PhpMapFile::write($file, ['key' => 'value']))->toThrow(ErrorException::class);
        expect(is_dir($file))->toBeTrue()
            ->and(file_get_contents($unrelated))->toBe('keep')
            ->and(scandir($root))->toBe(['.', '..', 'keep.txt', 'map.php']);
    } finally {
        unlink($unrelated);
        rmdir($file);
        rmdir($root);
    }
});
