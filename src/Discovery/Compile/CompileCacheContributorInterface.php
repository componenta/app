<?php

declare(strict_types=1);

namespace Componenta\App\Discovery\Compile;

interface CompileCacheContributorInterface
{
    /**
     * @param list<class-string> $classes
     *
     * @return array<string, mixed>
     */
    public function compile(array $classes): array;
}
