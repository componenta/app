<?php

declare(strict_types=1);

namespace Componenta\App\Build;

use Componenta\App\Config\AsConfig;
use Componenta\App\ConfigKey;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Reflection\Reflection;

/** Stores declarations only. Providers are still constructed and invoked at runtime. */
final readonly class ConfigProviderBuilder implements ApplicationBuilderInterface
{
    public function __construct(private ClassIteratorInterface $classes) {}

    public function build(BuildContext $build): void
    {
        $providers = [];
        $seen = [];
        foreach ($this->classes as $class) {
            $name = $class->fullyQualifiedName;
            $key = strtolower(ltrim($name, '\\'));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (Reflection::hasMetadata($class->reflector, AsConfig::class)) {
                $providers[] = $name;
            }
        }
        $build->write(ConfigKey::CONFIG_PROVIDERS_ARTIFACT, $providers);
    }
}
