<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Repository\ClassLocator;

final class ComposerClassLocator implements ClassLocator
{
    public function __construct(
        private readonly ComposerAutoloadMap $map,
    ) {
    }

    public function locate(ClassName $name): ?string
    {
        return $this->locateClass($name->fqn);
    }

    public function locateClass(string $fullyQualifiedName): ?string
    {
        $file = $this->map->classLoader()->findFile($fullyQualifiedName);
        return $file !== false ? $file : null;
    }
}
