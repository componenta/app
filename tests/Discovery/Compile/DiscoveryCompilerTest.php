<?php

declare(strict_types=1);

use Componenta\App\Discovery\Compile\DiscoveryCompiler;
use Componenta\ClassFinder\ClassListenerInterface;
use Componenta\ClassFinder\Compile\CompileResult;
use Componenta\ClassFinder\Compile\ListenerCompilerInterface;
use Componenta\ClassFinder\FinalizableListenerInterface;
use Componenta\ClassFinder\FinalizationStateInterface;
use Componenta\Tokenizer\ClassInfo;

final class DiscoveryCompilerSupportedListener implements FinalizableListenerInterface, FinalizationStateInterface
{
    private bool $isFinalized = false;

    public bool $finalized {
        get => $this->isFinalized;
    }

    public function handle(ClassInfo $info): void
    {
    }

    public function finalize(): void
    {
        $this->isFinalized = true;
    }
}

final class DiscoveryCompilerStatelessFinalizableListener implements FinalizableListenerInterface
{
    public function handle(ClassInfo $info): void
    {
    }

    public function finalize(): void
    {
    }
}

final class DiscoveryCompilerUnsupportedListener implements ClassListenerInterface
{
    public bool $handled = false;

    public function handle(ClassInfo $info): void
    {
        $this->handled = true;
    }
}

final class DiscoveryCompilerSupportedListenerCompiler implements ListenerCompilerInterface
{
    public function supports(object $listener): bool
    {
        return $listener instanceof DiscoveryCompilerSupportedListener
            || $listener instanceof DiscoveryCompilerStatelessFinalizableListener;
    }

    public function compile(object $listener, string $cacheDir): CompileResult
    {
        return CompileResult::config('componenta.test.listener', [
            'class' => $listener::class,
            'cacheDir' => $cacheDir,
        ]);
    }
}

describe('DiscoveryCompiler', function () {
    it('compiles finalized listeners supported by registered compilers', function () {
        $listener = new DiscoveryCompilerSupportedListener();
        $listener->finalize();
        $unsupported = new DiscoveryCompilerUnsupportedListener();

        $result = (new DiscoveryCompiler([
            new DiscoveryCompilerSupportedListenerCompiler(),
        ]))->compile([$listener, $unsupported], __DIR__);

        expect($result['componenta.test.listener'])->toBe([
            'class' => DiscoveryCompilerSupportedListener::class,
            'cacheDir' => __DIR__,
        ])->and($unsupported->handled)->toBeFalse();
    });

    it('rejects supported finalizable listeners that are not finalized', function () {
        $compiler = new DiscoveryCompiler([
            new DiscoveryCompilerSupportedListenerCompiler(),
        ]);

        expect(fn () => $compiler->compile([new DiscoveryCompilerSupportedListener()], __DIR__))
            ->toThrow(RuntimeException::class, 'before it is finalized');
    });

    it('requires supported finalizable listeners to expose finalization state', function () {
        $compiler = new DiscoveryCompiler([
            new DiscoveryCompilerSupportedListenerCompiler(),
        ]);

        expect(fn () => $compiler->compile([new DiscoveryCompilerStatelessFinalizableListener()], __DIR__))
            ->toThrow(RuntimeException::class, FinalizationStateInterface::class);
    });
});
