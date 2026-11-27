<?php

declare(strict_types=1);

use Componenta\App\Build\BuildStore;

require $argv[1];

$optional = new BuildStore($argv[2] . '/excluded');
$required = new BuildStore($argv[2] . '/excluded', requiredArtifacts: ['test.data']);
$warnings = 0;
$handler = static function (int $severity, string $message, string $file, int $line) use (&$warnings): never {
    ++$warnings;
    throw new ErrorException($message, 0, $severity, $file, $line);
};
set_error_handler($handler);
try {
    ini_set('open_basedir', $argv[2] . '/allowed');
    $optionalValue = 'unavailable';
    $optionalError = null;
    try {
        $optionalValue = $optional->load();
    } catch (Throwable $failure) {
        $optionalError = $failure::class;
    }
    $requiredError = null;
    $requiredMessage = null;
    try {
        $required->load();
    } catch (Throwable $failure) {
        $requiredError = $failure::class;
        $requiredMessage = $failure->getMessage();
    }
    $restored = set_error_handler(static fn (): bool => true);
    restore_error_handler();
    echo json_encode([
        'optional' => $optionalValue,
        'optionalError' => $optionalError,
        'requiredError' => $requiredError,
        'requiredMessage' => $requiredMessage,
        'callerWarnings' => $warnings,
        'handlerRestored' => $restored === $handler,
    ], JSON_THROW_ON_ERROR), "\n";
} finally {
    restore_error_handler();
}
