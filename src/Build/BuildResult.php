<?php

declare(strict_types=1);

namespace Componenta\App\Build;

final readonly class BuildResult
{
    /**
     * @param list<string> $sections
     */
    public function __construct(
        public string $generation,
        public string $directory,
        public string $manifest,
        public array $sections,
    ) {
    }
}
