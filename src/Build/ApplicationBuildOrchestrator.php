<?php

declare(strict_types=1);

namespace Componenta\App\Build;

final readonly class ApplicationBuildOrchestrator
{
    /** @var list<ApplicationBuilderInterface> */
    private array $builders;

    /** @param iterable<ApplicationBuilderInterface> $builders */
    public function __construct(iterable $builders)
    {
        $items = [];
        $seen = [];
        foreach ($builders as $builder) {
            if (!$builder instanceof ApplicationBuilderInterface) {
                throw new \InvalidArgumentException('Each application builder must implement ApplicationBuilderInterface.');
            }
            $identity = spl_object_id($builder);
            if (isset($seen[$identity])) {
                throw new \InvalidArgumentException('The same application builder instance cannot be registered twice.');
            }
            $seen[$identity] = true;
            $items[] = $builder;
        }
        $this->builders = $items;
    }

    public function build(): void
    {
        foreach ($this->builders as $builder) {
            $builder->build();
        }
    }

    public function clean(): void
    {
        foreach ($this->builders as $builder) {
            if ($builder instanceof ApplicationBuildCleanerInterface) {
                $builder->clean();
            }
        }
    }
}
