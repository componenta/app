<?php

declare(strict_types=1);

namespace Componenta\App;

final readonly class ContainerFactoryOptions
{
    public function __construct(
        public ContainerCacheMode $cacheMode = ContainerCacheMode::Auto,
    ) {}
}
