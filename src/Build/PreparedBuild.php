<?php

declare(strict_types=1);

namespace Componenta\App\Build;

use RuntimeException;

/** Immutable selection of one published generation, retained for the lifetime of a bootstrap. */
final readonly class PreparedBuild
{
    /** @internal Selected and validated by BuildStore::load() or publish(). */
    public function __construct(
        private string $directory,
        public string $generation,
        private array $artifacts,
        private array $requiredArtifacts = [],
    ) {}

    public function read(string $id, int $schemaVersion = 1): ?array
    {
        $artifact = $this->artifacts[$id] ?? null;
        if (!BuildContext::validId($id)
            || $schemaVersion < 1
            || ($artifact['state'] ?? null) !== 'written'
            || ($artifact['schema'] ?? null) !== $schemaVersion
        ) {
            return $this->unavailable($id);
        }

        return PhpMapFile::read($this->directory . '/' . $id . '.php', $this->generation) ?? $this->unavailable($id);
    }

    /** @return array<string, string> */
    public function skipped(): array
    {
        $skipped = [];
        foreach ($this->artifacts as $id => $artifact) {
            if ($artifact['state'] === 'skipped') {
                $skipped[$id] = $artifact['reason'];
            }
        }
        return $skipped;
    }

    private function unavailable(string $id): null
    {
        if (in_array($id, $this->requiredArtifacts, true)) {
            throw new RuntimeException(sprintf('Required build artifact "%s" is missing, unreadable or has an incompatible schema.', $id));
        }
        return null;
    }
}
