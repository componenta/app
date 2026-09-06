<?php

declare(strict_types=1);

namespace Componenta\App\Build;

final class ApplicationBuildOrchestrator
{
    /** @param iterable<ApplicationBuilderInterface> $builders */
    public function __construct(
        private iterable $builders,
    ) {}

    public function build(): void
    {
        foreach ($this->builders as $builder) {
            $builder->build();
        }
    }
}