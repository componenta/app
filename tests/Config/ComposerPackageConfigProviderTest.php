<?php

declare(strict_types=1);

use Componenta\App\Config\ComposerPackageConfigProvider;
use Componenta\Config\ConfigProvider;

function composerPackageConfigProviderRuntimeFile(string $name): string
{
    $root = str_replace('\\', '/', sys_get_temp_dir()) . '/componenta_composer_provider_' . bin2hex(random_bytes(4));

    if (!mkdir($root, 0o755, recursive: true) && !is_dir($root)) {
        throw new RuntimeException('Failed to create composer package provider test runtime.');
    }

    return $root . '/' . $name;
}

function removeComposerPackageConfigProviderRuntimeFile(string $file): void
{
    if (is_file($file)) {
        unlink($file);
    }

    $dir = dirname($file);

    if (is_dir($dir)) {
        rmdir($dir);
    }
}

it('returns an empty provider list when generated provider file does not exist', function () {
    $file = composerPackageConfigProviderRuntimeFile('missing.php');

    try {
        expect((new ComposerPackageConfigProvider($file))->classes())->toBe([]);
    } finally {
        removeComposerPackageConfigProviderRuntimeFile($file);
    }
});

it('extracts and materializes composer package providers in file order without invoking them', function () {
    $file = composerPackageConfigProviderRuntimeFile('providers.php');
    file_put_contents(
        $file,
        "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n"
        . ComposerPackageConfigProviderFirstFixture::class . "::class,\n"
        . ComposerPackageConfigProviderSecondFixture::class . "::class,\n"
        . "];\n",
    );

    try {
        ComposerPackageConfigProviderFirstFixture::$calls = 0;
        ComposerPackageConfigProviderSecondFixture::$calls = 0;
        $source = new ComposerPackageConfigProvider($file);
        $classes = $source->classes();
        $providers = iterator_to_array($source->materialize($classes));

        expect($classes)->toBe([
            ComposerPackageConfigProviderFirstFixture::class,
            ComposerPackageConfigProviderSecondFixture::class,
        ])->and($providers)->toHaveCount(2)
            ->and($providers[0])->toBeInstanceOf(ComposerPackageConfigProviderFirstFixture::class)
            ->and($providers[1])->toBeInstanceOf(ComposerPackageConfigProviderSecondFixture::class)
            ->and(ComposerPackageConfigProviderFirstFixture::$calls)->toBe(0)
            ->and(ComposerPackageConfigProviderSecondFixture::$calls)->toBe(0);
    } finally {
        removeComposerPackageConfigProviderRuntimeFile($file);
    }
});

it('rejects invalid generated provider files', function () {
    $file = composerPackageConfigProviderRuntimeFile('invalid.php');
    file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\nreturn 'invalid';\n");

    try {
        expect(fn () => (new ComposerPackageConfigProvider($file))->classes())
            ->toThrow(RuntimeException::class, 'must return an iterable list');
    } finally {
        removeComposerPackageConfigProviderRuntimeFile($file);
    }
});

final class ComposerPackageConfigProviderFirstFixture extends ConfigProvider
{
    public static int $calls = 0;

    protected function getConfig(): array
    {
        self::$calls++;

        return [
            'feature' => [
                'enabled' => true,
                'name' => 'first',
            ],
        ];
    }

    protected function getServices(): array
    {
        return [
            'first' => 'registered',
        ];
    }
}

final class ComposerPackageConfigProviderSecondFixture extends ConfigProvider
{
    public static int $calls = 0;

    protected function getConfig(): array
    {
        self::$calls++;

        return [
            'feature' => [
                'name' => 'second',
            ],
        ];
    }

    protected function getServices(): array
    {
        return [
            'second' => 'registered',
        ];
    }
}
