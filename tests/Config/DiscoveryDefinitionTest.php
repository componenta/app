<?php

declare(strict_types=1);

use Componenta\App\Config\DiscoveryDefinition;

it('rejects invalid discovery paths and excludes at the public construction boundary', function (array $directories, array $exclude): void {
    expect(fn () => new DiscoveryDefinition($directories, $exclude))->toThrow(InvalidArgumentException::class);
})->with([
    'no directories' => [[], []],
    'empty path' => [[''], []],
    'non-string directory' => [[2], []],
    'associative directories' => [['src' => 'src'], []],
    'empty exclude' => [['src'], ['']],
    'non-string exclude' => [['src'], [false]],
    'associative exclude' => [['src'], ['ignore' => 'ignore']],
]);

it('preserves valid ordered discovery directories and exclude patterns', function (): void {
    $definition = new DiscoveryDefinition(['src', 'plugins'], ['vendor', 'generated']);
    expect($definition->directories)->toBe(['src', 'plugins'])
        ->and($definition->exclude)->toBe(['vendor', 'generated']);
});
