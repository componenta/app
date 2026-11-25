<?php

declare(strict_types=1);

namespace Componenta\App\Config;

use Componenta\ClassFinder\ClassIteratorInterface;

interface DiscoveryAwareConfigProviderInterface
{
    public function withDiscovered(?ClassIteratorInterface $discovered): static;
}
