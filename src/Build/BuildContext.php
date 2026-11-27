<?php

declare(strict_types=1);

namespace Componenta\App\Build;

use InvalidArgumentException;
use LogicException;

/** A single build's write capability. Participants never choose publication paths. */
final class BuildContext
{
    /** @var array<string, array{state: 'written', schema: int}|array{state: 'skipped', reason: string}> */
    private array $artifacts = [];
    private bool $sealed = false;

    /** @internal Obtain a context from BuildStore::begin(). */
    public function __construct(
        private readonly string $directory,
        public readonly string $generation,
        private readonly object $owner,
    ) {}

    private function __clone() {}

    /** A write capability cannot be detached from its live publication lifecycle. */
    public function __serialize(): array
    {
        throw new LogicException('Build contexts cannot be serialized.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('Build contexts cannot be unserialized.');
    }

    /** Serialization completes before this method returns; failures leave the ID available for skip(). */
    public function write(string $id, array $payload, int $schemaVersion = 1): void
    {
        $this->claim($id);
        if ($schemaVersion < 1) {
            throw new InvalidArgumentException('Artifact schema version must be positive.');
        }

        PhpMapFile::write($this->directory . '/' . $id . '.php', $payload, $this->generation);
        $this->artifacts[$id] = ['state' => 'written', 'schema' => $schemaVersion];
    }

    public function skip(string $id, string $reason): void
    {
        $this->claim($id);
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A skipped artifact requires a reason.');
        }
        $this->artifacts[$id] = ['state' => 'skipped', 'reason' => $reason];
    }

    /** @internal Only the creating store may finish this context. */
    public function seal(object $owner, bool $abandon = false): array
    {
        if ($owner !== $this->owner) {
            throw new LogicException('Build context belongs to another store.');
        }
        if ($this->sealed && !$abandon) {
            throw new LogicException('Build context has already been published or abandoned.');
        }
        $this->sealed = true;

        return $this->artifacts;
    }

    /** @internal Shared validation for manifest readers. */
    public static function validId(string $id): bool
    {
        return preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/', $id) === 1;
    }

    private function claim(string $id): void
    {
        if ($this->sealed) {
            throw new LogicException('Build context is closed.');
        }
        if (!self::validId($id)) {
            throw new InvalidArgumentException(sprintf('Invalid artifact ID "%s".', $id));
        }
        if (isset($this->artifacts[$id])) {
            throw new LogicException(sprintf('Artifact "%s" was already written or skipped.', $id));
        }
    }
}
