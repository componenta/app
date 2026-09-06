<?php

declare(strict_types=1);

namespace Componenta\App\Build;

interface ApplicationBuilderInterface
{
    /**
     * Build owned artifacts using constructor-injected source dependencies.
     *
     * Creating a builder must not perform the build or publish its artifacts.
     * Each builder owns its output format, directories and atomic publication;
     * it must not depend on outputs of other builders in the same run.
     */
    public function build(): void;
}