<?php

namespace Componenta\App\Boot;

interface BootloaderProviderInterface
{
    /**
     * @return iterable<BootloaderInterface>
     */
    public function provideFor(BootContext $context): iterable ;
}
