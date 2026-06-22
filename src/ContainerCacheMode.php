<?php

declare(strict_types=1);

namespace Componenta\App;

enum ContainerCacheMode
{
    case Auto;
    case Disabled;
    case CacheFile;
    case FactoryFile;
    case RequireCache;
}
