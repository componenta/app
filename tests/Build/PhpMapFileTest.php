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
