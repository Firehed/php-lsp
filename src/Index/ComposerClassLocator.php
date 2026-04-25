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

        $loader = new ClassLoader();

        $psr4File = $composerDir . '/autoload_psr4.php';
        if (file_exists($psr4File)) {
            /** @var array<string, list<string>> $psr4 */
            $psr4 = require $psr4File;
            foreach ($psr4 as $namespace => $paths) {
                $loader->setPsr4($namespace, $paths);
            }
        }

        $psr0File = $composerDir . '/autoload_namespaces.php';
        if (file_exists($psr0File)) {
            /** @var array<string, list<string>> $psr0 */
            $psr0 = require $psr0File;
            foreach ($psr0 as $namespace => $paths) {
                $loader->set($namespace, $paths);
            }
        }

        $classmapFile = $composerDir . '/autoload_classmap.php';
        if (file_exists($classmapFile)) {
            /** @var array<class-string, string> $classmap */
            $classmap = require $classmapFile;
            $loader->addClassMap($classmap);
        }

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
