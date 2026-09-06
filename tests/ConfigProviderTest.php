<?php

declare(strict_types=1);

use Componenta\App\Boot\BootMethodInvocation;
use Componenta\App\Boot\BootInvocationRunner;
use Componenta\App\Boot\BootloaderProvider;
use Componenta\App\Boot\BootTargetFactory;
use Componenta\App\Boot\ClassDiscoveryBootloader;
use Componenta\App\Boot\DateTimeBootloader;
use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\App\Build\ApplicationBuildOrchestratorFactory;
use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\App\ConfigProvider;
use Componenta\ClassFinder\ConfigKey as ClassFinderConfigKey;
use Componenta\Config\ConfigKey as DependencyConfigKey;

it('registers runtime application services without Config or DI cache definitions', function (): void {
    $provided = (new ConfigProvider())();
    $dependencies = $provided[DependencyConfigKey::DEPENDENCIES];

    expect($provided[AppConfigKey::BOOTLOADERS])->toBe([
        DateTimeBootloader::class,
        ClassDiscoveryBootloader::class,
    ])->and($provided[ClassFinderConfigKey::LISTENERS])->toBe([
        BootMethodInvocation::class,
    ])->and($dependencies)->not->toHaveKey(DependencyConfigKey::INVOKABLES)
        ->and($dependencies[DependencyConfigKey::FACTORIES])->toBe([
            ApplicationBuildOrchestrator::class => ApplicationBuildOrchestratorFactory::class,
        ])
        ->and($provided)->not->toHaveKey('Componenta\\App\\Discovery::cache')
        ->and($dependencies)->not->toHaveKey('plans');
});
