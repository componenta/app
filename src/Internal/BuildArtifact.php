<?php

declare(strict_types=1);

namespace Componenta\App\Internal;

use InvalidArgumentException;

/** @internal Versioned envelope for independently published discovery and boot maps. */
final class BuildArtifact
{
    public const int VERSION = 1;

    public static function newGeneration(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** @return array{version: int, generation: string, payload: array} */
    public static function wrap(string $generation, array $payload): array
    {
        if (!self::validGeneration($generation)) {
            throw new InvalidArgumentException('Invalid build generation.');
        }

        return ['version' => self::VERSION, 'generation' => $generation, 'payload' => $payload];
    }

    /** @return array{version: int, generation: string, payload: array}|null */
    public static function unwrap(?array $map): ?array
    {
        if ($map === null
            || ($map['version'] ?? null) !== self::VERSION
            || !is_string($map['generation'] ?? null)
            || !self::validGeneration($map['generation'])
            || !is_array($map['payload'] ?? null)
        ) {
            return null;
        }

        return ['version' => self::VERSION, 'generation' => $map['generation'], 'payload' => $map['payload']];
    }

    private static function validGeneration(string $generation): bool
    {
        return preg_match('/\A[0-9a-f]{32}\z/', $generation) === 1;
    }

    private function __construct() {}
}
