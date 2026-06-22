<?php

declare(strict_types=1);

namespace Componenta\App\Boot\Compile;

use Componenta\App\Boot\BootInvocationProviderInterface;
use Componenta\App\ConfigKey;
use Componenta\ClassFinder\Compile\CompileResult;
use Componenta\ClassFinder\Compile\ListenerCompilerInterface;

/**
 * Compiles discovered `#[Boot]` methods into production config cache metadata.
 */
final class BootInvocationCompiler implements ListenerCompilerInterface
{
    public function supports(object $listener): bool
    {
        return $listener instanceof BootInvocationProviderInterface;
    }

    public function compile(object $listener, string $cacheDir): CompileResult
    {
        assert($listener instanceof BootInvocationProviderInterface);

        return CompileResult::config(
            ConfigKey::BOOT_INVOCATIONS,
            array_map(BootInvocationSerializer::serialize(...), $listener->bootInvocations),
        );
    }
}
