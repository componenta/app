<?php

declare(strict_types=1);

namespace Componenta\App;

use Componenta\Scope\ScopeInterface;

/**
 * Execution context marker for bootloader scheduling.
 */
enum Scope: string implements ScopeInterface
{
    case CLI       = 'cli';
    case HTTP      = 'http';
    case WEBSOCKET = 'websocket';

    public function matches(ScopeInterface $scope): bool
    {
        return $this->value === $scope->value;
    }
}
