<?php

declare(strict_types=1);

use Componenta\App\Config\ComposerPackageConfigProvider;
use Componenta\Config\ConfigKey;
use Componenta\Config\ConfigLoader;
use Componenta\Config\ConfigProvider;

it('preserves DI composition semantics across package and outer providers', function () {
    $root = str_replace('\\', '/', sys_get_temp_dir())
        . '/componenta_composer_composition_'
        . bin2hex(random_bytes(4));

    if (!mkdir($root, 0o755, recursive: true) && !is_dir($root)) {
        throw new RuntimeException('Failed to create composer provider test runtime.');
    }

    $file = $root . '/providers.php';
    file_put_contents(
        $file,
        "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n"
        . ComposerCompositionFirstFixture::class . "::class,\n"
        . ComposerCompositionSecondFixture::class . "::class,\n"
        . "];\n",
    );

    try {
        $config = ConfigLoader::load(
            null,
            new ComposerPackageConfigProvider($file),
            static fn (): array => [
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::FACTORIES => [
                        'shared.factory' => ['factory.c', 'make'],
                    ],
                    ConfigKey::SERVICES => [
                        'shared.service' => ['source' => 'outer'],
                    ],
                    ConfigKey::PARAMETER_RESOLVERS => [
                        700 => 'ResolverC',
                    ],
                ],
            ],
        );
        $dependencies = $config->get(ConfigKey::DEPENDENCIES);

        expect($dependencies[ConfigKey::PARAMETER_RESOLVERS])
            ->toBe([
                1200 => 'ResolverA',
                900 => 'ResolverB',
                700 => 'ResolverC',
            ])
            ->and($dependencies[ConfigKey::FACTORIES]['shared.factory'])
            ->toBe(['factory.c', 'make'])
            ->and($dependencies[ConfigKey::SERVICES]['shared.service'])
            ->toBe(['source' => 'outer'])
            ->and($dependencies[ConfigKey::PARAMETER_RESOLVERS_REPLACE])
            ->toBeFalse();
    } finally {
        if (is_file($file)) {
            unlink($file);
        }

        if (is_dir($root)) {
            rmdir($root);
        }
    }
});

final class ComposerCompositionFirstFixture extends ConfigProvider
{
    protected function getFactories(): array
    {
        return [
            'shared.factory' => ['factory.a', 'create'],
        ];
    }

    protected function getServices(): array
    {
        return [
            'shared.service' => [
                'source' => 'first',
                'stale' => true,
            ],
        ];
    }

    protected function getParameterResolvers(): array
    {
        return [
            1200 => 'ResolverA',
        ];
    }

    protected function shouldReplaceParameterResolvers(): bool
    {
        return true;
    }
}

final class ComposerCompositionSecondFixture extends ConfigProvider
{
    protected function getFactories(): array
    {
        return [
            'shared.factory' => ['factory.b', 'build'],
        ];
    }

    protected function getServices(): array
    {
        return [
            'shared.service' => ['source' => 'second'],
        ];
    }

    protected function getParameterResolvers(): array
    {
        return [
            900 => 'ResolverB',
        ];
    }

    protected function shouldReplaceParameterResolvers(): bool
    {
        return false;
    }
}
