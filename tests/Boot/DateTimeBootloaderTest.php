<?php

declare(strict_types=1);

use Componenta\App\Boot\BootContext;
use Componenta\App\Boot\DateTimeBootloader;
use Componenta\App\Scope;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Psr\Container\ContainerInterface;

function timezoneBootContext(Scope $scope, array $environment): BootContext
{
    $container = new class implements ContainerInterface {
        public function get(string $id): mixed { throw new LogicException('No services are required.'); }
        public function has(string $id): bool { return false; }
    };
    return new BootContext(new ContainerValue($container, new Config([], new Environment($environment))), $scope, new stdClass());
}

it('sets the configured timezone in every standard runtime scope', function (Scope $scope): void {
    $previous = date_default_timezone_get();
    try {
        $bootloader = new DateTimeBootloader();
        $context = timezoneBootContext($scope, ['APP_TIMEZONE' => 'Europe/Stockholm']);
        expect($bootloader->supports($context))->toBeTrue();
        $bootloader->boot($context);
        expect(date_default_timezone_get())->toBe('Europe/Stockholm');
        $bootloader->boot(timezoneBootContext($scope, []));
        expect(date_default_timezone_get())->toBe('UTC');
    } finally {
        date_default_timezone_set($previous);
    }
})->with([Scope::HTTP, Scope::CLI, Scope::WEBSOCKET]);

it('fails startup on an invalid timezone and restores the previous error handler', function (): void {
    $previous = date_default_timezone_get();
    $handler = static fn (): bool => true;
    set_error_handler($handler);
    try {
        expect(fn () => (new DateTimeBootloader())->boot(timezoneBootContext(Scope::HTTP, ['APP_TIMEZONE' => 'Invalid/Timezone'])))
            ->toThrow(InvalidArgumentException::class, 'Invalid APP_TIMEZONE');
        expect(date_default_timezone_get())->toBe($previous);
        $actual = set_error_handler(static fn (): bool => true);
        restore_error_handler();
        expect($actual)->toBe($handler);
    } finally {
        restore_error_handler();
        date_default_timezone_set($previous);
    }
});
