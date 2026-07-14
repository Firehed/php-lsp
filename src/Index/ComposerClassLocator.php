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

        $map = ComposerAutoloadMap::fromProjectRoot($projectRoot);
        $loader = new ClassLoader();

        foreach ($map->psr4Prefixes() as $namespace => $paths) {
            $loader->setPsr4($namespace, $paths);
        }

        foreach ($map->psr0Prefixes() as $namespace => $paths) {
            $loader->set($namespace, $paths);
        }

        /** @var array<class-string, string> $classMap */
        $classMap = $map->classMap();
        $loader->addClassMap($classMap);

        $this->loader = $loader;
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
