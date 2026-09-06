<?php

declare(strict_types=1);

namespace Componenta\App\Discovery\Artifact;

final readonly class PublishedGeneration
{
    public function __construct(
        public string $generation,
        public string $directory,
        public string $manifest,
    ) {
    }
}
