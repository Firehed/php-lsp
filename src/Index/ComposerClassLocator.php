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
        $autoloadFile = rtrim($projectRoot, '/') . '/vendor/autoload.php';

        if (file_exists($autoloadFile)) {
            $loader = require $autoloadFile;
            if ($loader instanceof ClassLoader) {
                $this->loader = $loader;
            }
        }
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
