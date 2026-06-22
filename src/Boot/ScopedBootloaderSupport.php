<?php

declare(strict_types=1);

namespace Componenta\App\Boot;

trait ScopedBootloaderSupport
{
    final public function supports(BootContext $context): bool
    {
        return $this->scopes->contains($context->scope)
            && $this->supportsContext($context);
    }

    protected function supportsContext(BootContext $context): bool
    {
        return true;
    }
}
