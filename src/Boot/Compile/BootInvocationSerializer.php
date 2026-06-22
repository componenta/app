<?php

declare(strict_types=1);

namespace Componenta\App\Boot\Compile;

use Componenta\App\Boot\BootInvocation;
use Componenta\Config\ConfigPath;
use Componenta\DI\Attribute\Config as ConfigAttr;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Resolver\ConfigValueExtractor;
use InvalidArgumentException;

/**
 * Converts boot invocation metadata to cache-safe arrays and back.
 */
final class BootInvocationSerializer
{
    private const string TYPE_VALUE = 'value';
    private const string TYPE_ENTRY = 'entry';
    private const string TYPE_CONFIG = 'config';
    private const string TYPE_ENV = 'env';

    /**
     * @return array{class: string, method: string, priority: int, params: array<string|int, mixed>}
     */
    public static function serialize(BootInvocation $invocation): array
    {
        return [
            'class' => $invocation->class,
            'method' => $invocation->method,
            'priority' => $invocation->priority,
            'params' => array_map(self::serializeParam(...), $invocation->params),
        ];
    }

    /**
     * @param array{class: string, method: string, priority?: int, params?: array<string|int, mixed>} $payload
     */
    public static function deserialize(array $payload): BootInvocation
    {
        return new BootInvocation(
            class: $payload['class'],
            method: $payload['method'],
            priority: $payload['priority'] ?? 0,
            params: array_map(self::deserializeParam(...), $payload['params'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeParam(mixed $value): array
    {
        if ($value instanceof EntryId) {
            return [
                'type' => self::TYPE_ENTRY,
                'value' => $value->value,
            ];
        }

        if ($value instanceof ConfigAttr) {
            return [
                'type' => self::TYPE_CONFIG,
                'mode' => match (true) {
                    $value->path instanceof ConfigPath => ConfigValueExtractor::MODE_PATH,
                    $value->path === null => ConfigValueExtractor::MODE_IMPLICIT,
                    default => ConfigValueExtractor::MODE_LITERAL,
                },
                'key' => $value->path instanceof ConfigPath ? $value->path->value : $value->path,
                'default' => $value->default,
            ];
        }

        if ($value instanceof Env) {
            return [
                'type' => self::TYPE_ENV,
                'name' => $value->name,
                'default' => $value->default,
            ];
        }

        return [
            'type' => self::TYPE_VALUE,
            'value' => $value,
        ];
    }

    private static function deserializeParam(mixed $payload): mixed
    {
        if (!is_array($payload) || !isset($payload['type'])) {
            return $payload;
        }

        return match ($payload['type']) {
            self::TYPE_ENTRY => new EntryId($payload['value']),
            self::TYPE_CONFIG => self::deserializeConfigParam($payload),
            self::TYPE_ENV => new Env($payload['name'] ?? null, $payload['default']),
            self::TYPE_VALUE => $payload['value'] ?? null,
            default => throw new InvalidArgumentException("Unsupported boot invocation parameter type: {$payload['type']}"),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function deserializeConfigParam(array $payload): ConfigAttr
    {
        return match ($payload['mode']) {
            ConfigValueExtractor::MODE_PATH => new ConfigAttr(new ConfigPath($payload['key']), $payload['default']),
            ConfigValueExtractor::MODE_LITERAL => new ConfigAttr($payload['key'], $payload['default']),
            ConfigValueExtractor::MODE_IMPLICIT => new ConfigAttr(default: $payload['default']),
            default => throw new InvalidArgumentException("Unsupported boot config parameter mode: {$payload['mode']}"),
        };
    }
}
