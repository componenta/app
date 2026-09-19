<?php

declare(strict_types=1);

namespace Componenta\App\Build;

/** Optional capability for builders that own removable artifacts. */
interface ApplicationBuildCleanerInterface
{
    /** Remove owned artifacts; an already absent artifact is not an error. */
    public function clean(): void;
}
