<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Composer\Autoload\ClassLoader;

final class ComposerClassLocator
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

    /**
     * @return list<string>
     */
    public function getAllClasses(): array
    {
        if ($this->loader === null) {
            return [];
        }

        return array_keys($this->loader->getClassMap());
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
