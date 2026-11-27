<?php

declare(strict_types=1);

namespace Componenta\App\Build;

interface ApplicationBuilderInterface
{
    /**
     * Prepare artifacts using constructor-injected source dependencies.
     *
     * Construction must not build. The participant owns payload schemas, while
     * the context owns file placement and the store owns publication. Participants
     * must not read outputs of another participant in the same run or write to
     * an active generation. External effects are outside this artifact protocol.
     */
    public function build(BuildContext $build): void;
}
