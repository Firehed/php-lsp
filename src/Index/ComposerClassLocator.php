<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Composer\Autoload\ClassLoader;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Repository\ClassLocator;

final class ComposerClassLocator implements ClassLocator
{
    private ?ClassLoader $loader = null;

    public function __construct(string $projectRoot)
    {
        $composerDir = rtrim($projectRoot, '/') . '/vendor/composer';

        if (!is_dir($composerDir)) {
            return;
        }

        $this->loader = ComposerAutoloadMap::fromProjectRoot($projectRoot)->classLoader();
    }

    public function locate(ClassName $name): ?string
    {
        return $this->locateClass($name->fqn);
    }

    public function locateClass(string $fullyQualifiedName): ?string
    {
        if ($this->loader === null) {
            return null;
        }

        $file = $this->loader->findFile($fullyQualifiedName);
        return $file !== false ? $file : null;
    }
}
