<?php

declare(strict_types=1);

use Componenta\App\Config\ComposerPackageConfigProvider;
use Componenta\Config\ConfigKey;
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

it('returns empty config when generated provider file does not exist', function () {
    $file = composerPackageConfigProviderRuntimeFile('missing.php');

    try {
        expect((new ComposerPackageConfigProvider($file))())->toBe([]);
    } finally {
        removeComposerPackageConfigProviderRuntimeFile($file);
    }
});

it('loads and merges composer package providers in file order', function () {
    $file = composerPackageConfigProviderRuntimeFile('providers.php');
    file_put_contents(
        $file,
        "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n"
        . ComposerPackageConfigProviderFirstFixture::class . "::class,\n"
        . ComposerPackageConfigProviderSecondFixture::class . "::class,\n"
        . "];\n",
    );

    try {
        $config = (new ComposerPackageConfigProvider($file))();

        expect($config['feature']['enabled'])->toBeTrue()
            ->and($config['feature']['name'])->toBe('second')
            ->and($config[ConfigKey::DEPENDENCIES][ConfigKey::SERVICES]['first'])->toBe('registered')
            ->and($config[ConfigKey::DEPENDENCIES][ConfigKey::SERVICES]['second'])->toBe('registered');
    } finally {
        removeComposerPackageConfigProviderRuntimeFile($file);
    }
});

it('rejects invalid generated provider files', function () {
    $file = composerPackageConfigProviderRuntimeFile('invalid.php');
    file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\nreturn 'invalid';\n");

    try {
        expect(fn () => (new ComposerPackageConfigProvider($file))())
            ->toThrow(RuntimeException::class, 'must return an iterable list');
    } finally {
        removeComposerPackageConfigProviderRuntimeFile($file);
    }
});

final class ComposerPackageConfigProviderFirstFixture extends ConfigProvider
{
    protected function getConfig(): array
    {
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
    protected function getConfig(): array
    {
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
