<?php

declare(strict_types=1);

namespace Componenta\App\Build;

use Throwable;

final readonly class ApplicationBuildOrchestrator
{
    /** @var list<ApplicationBuilderInterface> */
    private array $builders;

    /** @param iterable<ApplicationBuilderInterface> $builders */
    public function __construct(iterable $builders, private BuildStore $store)
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

    public function build(): PreparedBuild
    {
        $build = $this->store->begin();
        try {
            foreach ($this->builders as $builder) {
                $builder->build($build);
            }

            return $this->store->publish($build);
        } catch (Throwable $failure) {
            $this->store->abandon($build);
            throw $failure;
        }
    }

    public function clean(): void
    {
        $this->store->clean();
    }
}
