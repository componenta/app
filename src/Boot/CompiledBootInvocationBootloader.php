<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

use Componenta\App\ConfigKey;
use Componenta\App\Scope;
use Componenta\App\Boot\Compile\BootInvocationSerializer;
use Componenta\Scope\Scopes;

/**
 * Executes compiled `#[Boot]` invocations in production.
 */
final readonly class CompiledBootInvocationBootloader implements BootloaderInterface
{
    use ScopedBootloaderSupport;

    public Scopes $scopes;

    public function __construct(
        private BootInvocationRunnerInterface $runner,
    ) {
        $this->scopes = Scopes::of(Scope::HTTP, Scope::CLI, Scope::WEBSOCKET);
    }

    public function boot(BootContext $context): void
    {
        $this->runner->run(array_map(
            BootInvocationSerializer::deserialize(...),
            $context->container->config->get(ConfigKey::BOOT_INVOCATIONS, []),
        ));
    }

    protected function supportsContext(BootContext $context): bool
    {
        return $context->container->config->environment?->match('APP_ENV', 'production', false) === true
            && $context->container->config->has(ConfigKey::BOOT_INVOCATIONS);
    }
}
