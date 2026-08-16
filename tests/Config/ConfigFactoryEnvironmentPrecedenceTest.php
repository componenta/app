<?php

declare(strict_types=1);

use Componenta\App\Config\ConfigDefinition;
use Componenta\App\Config\ConfigFactory;
use Componenta\Stdlib\PathResolver;

function environmentPrecedenceRoot(): string
{
    $root = sys_get_temp_dir() . '/componenta_app_env_' . bin2hex(random_bytes(4));
    mkdir($root . '/var/cache/build', 0o700, recursive: true);
    return $root;
}

function removeEnvironmentPrecedenceRoot(string $root): void
{
    if (!is_dir($root)) { return; }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
}

function snapshotEnvironmentKeys(array $keys): array
{
    $snapshot = [];
    foreach ($keys as $key) {
        $snapshot[$key] = [
            'env' => $_ENV[$key] ?? null,
            'env_exists' => array_key_exists($key, $_ENV),
            'server' => $_SERVER[$key] ?? null,
            'server_exists' => array_key_exists($key, $_SERVER),
            'native' => getenv($key),
        ];
        unset($_ENV[$key], $_SERVER[$key]); putenv($key);
    }
    return $snapshot;
}

function restoreEnvironmentKeys(array $snapshot): void
{
    foreach ($snapshot as $key => $state) {
        unset($_ENV[$key], $_SERVER[$key]); putenv($key);
        if ($state['env_exists']) { $_ENV[$key] = $state['env']; }
        if ($state['server_exists']) { $_SERVER[$key] = $state['server']; }
        if ($state['native'] !== false) { putenv($key . '=' . $state['native']); }
    }
}

it('loads only the explicit environment file chain and keeps process values authoritative', function (): void {
    $keys = ['APP_ENV', 'FILE_LAYER', 'PROCESS_VALUE', 'PROCESS_ONLY', 'IGNORED_SAMPLE'];
    $snapshot = snapshotEnvironmentKeys($keys);
    $root = environmentPrecedenceRoot();
    file_put_contents($root . '/.env', "APP_ENV=development\nFILE_LAYER=base\nPROCESS_VALUE=file\n");
    file_put_contents($root . '/.env.local', "FILE_LAYER=local\n");
    file_put_contents($root . '/.env.development', "FILE_LAYER=environment\n");
    file_put_contents($root . '/.env.development.local', "FILE_LAYER=environment-local\n");
    file_put_contents($root . '/.env.example', "IGNORED_SAMPLE=example\nAPP_ENV=test\n");
    file_put_contents($root . '/.env.test', "IGNORED_SAMPLE=test\n");
    file_put_contents($root . '/.env.bak', "IGNORED_SAMPLE=backup\n");
    $_ENV['PROCESS_VALUE'] = 'process'; $_ENV['PROCESS_ONLY'] = 'process-only';

    try {
        $result = ConfigFactory::create(new PathResolver($root), static fn (): ConfigDefinition => new ConfigDefinition(providers: [static fn (): array => []]));
        $environment = $result->config->environment;
        expect($environment)->not->toBeNull()
            ->and($environment?->string('APP_ENV'))->toBe('development')
            ->and($environment?->string('FILE_LAYER'))->toBe('environment-local')
            ->and($environment?->string('PROCESS_VALUE'))->toBe('process')
            ->and($environment?->string('PROCESS_ONLY'))->toBe('process-only')
            ->and($environment?->has('IGNORED_SAMPLE'))->toBeFalse()
            ->and($_ENV)->not->toHaveKey('IGNORED_SAMPLE');
    } finally {
        removeEnvironmentPrecedenceRoot($root); restoreEnvironmentKeys($snapshot);
    }
});

it('rejects unsafe APP_ENV values before deriving an environment filename', function (): void {
    $snapshot = snapshotEnvironmentKeys(['APP_ENV']);
    $root = environmentPrecedenceRoot();
    file_put_contents($root . '/.env', "APP_ENV=../production\n");
    try {
        expect(fn () => ConfigFactory::create(new PathResolver($root), static fn (): ConfigDefinition => new ConfigDefinition()))
            ->toThrow(RuntimeException::class, 'APP_ENV');
    } finally {
        removeEnvironmentPrecedenceRoot($root); restoreEnvironmentKeys($snapshot);
    }
});
