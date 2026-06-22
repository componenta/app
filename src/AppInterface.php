<?php

declare(strict_types=1);

namespace Componenta\App;

interface AppInterface
{
    /**
     * @internal Invoked by {@see Runner} after all bootloaders complete.
     * Not intended for user code or bootloaders.
     *
     * Returns a process exit code when the application owns one, or null
     * when the surrounding entrypoint should use its default behavior.
     */
    public function run(): ?int;
}
