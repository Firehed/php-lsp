<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Composer\Autoload\ClassLoader;

final class ComposerClassLocator
{
    private ?ClassLoader $loader = null;

    /** @var array<string, list<string>> namespace prefix => directories */
    private array $psr4Mappings = [];

    public function __construct(string $projectRoot)
    {
        $projectRoot = rtrim($projectRoot, '/');
        $this->loadFromComposerAutoload($projectRoot);
    }

    /**
     * @return list<string>
     */
    public function getAllClasses(): array
    {
        $classmap = $this->loader?->getClassMap() ?? [];

        // Start with all classes from classmap
        $seen = $classmap;

        // Scan PSR-4 directories for classes not in classmap
        foreach ($this->psr4Mappings as $prefix => $directories) {
            foreach ($directories as $directory) {
                if (!is_dir($directory)) {
                    continue;
                }
                $this->scanDirectory($directory, $prefix, $seen);
            }
        }

        return array_keys($seen);
    }

    /**
     * @param array<string, string> $seen FQCN => file path (modified by reference)
     */
    private function scanDirectory(string $directory, string $namespacePrefix, array &$seen): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($directory) + 1);
            $className = $namespacePrefix . str_replace(['/', '.php'], ['\\', ''], $relativePath);

            if (!array_key_exists($className, $seen)) {
                $seen[$className] = $file->getPathname();
            }
        }
    }

    public function locateClass(string $fullyQualifiedName): ?string
    {
        if ($this->loader !== null) {
            $file = $this->loader->findFile($fullyQualifiedName);
            if ($file !== false) {
                return $file;
            }
        }

        return null;
    }

    private function loadFromComposerAutoload(string $projectRoot): void
    {
        $autoloadFile = $projectRoot . '/vendor/autoload.php';

        if (file_exists($autoloadFile)) {
            $loader = require $autoloadFile;
            if ($loader instanceof ClassLoader) {
                $this->loader = $loader;

                // Extract PSR-4 mappings for directory scanning
                foreach ($loader->getPrefixesPsr4() as $prefix => $dirs) {
                    $prefix = rtrim($prefix, '\\') . '\\';
                    $this->psr4Mappings[$prefix] = [];
                    foreach ($dirs as $dir) {
                        $this->psr4Mappings[$prefix][] = rtrim($dir, '/');
                    }
                }
            }
        }

        // Also load project's own composer.json for paths not in vendor
        // (in case autoload hasn't been dumped recently)
        $this->loadFromComposerJson($projectRoot);
    }

    private function loadFromComposerJson(string $projectRoot): void
    {
        $composerPath = $projectRoot . '/composer.json';
        if (!file_exists($composerPath)) {
            return;
        }

        $content = file_get_contents($composerPath);
        if ($content === false) {
            return;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return;
        }

        // Load PSR-4 from autoload
        $autoload = $data['autoload'] ?? [];
        if (is_array($autoload)) {
            $psr4 = $autoload['psr-4'] ?? [];
            if (is_array($psr4)) {
                $this->loadPsr4($psr4, $projectRoot);
            }
        }

        // Also load from autoload-dev for test classes
        $autoloadDev = $data['autoload-dev'] ?? [];
        if (is_array($autoloadDev)) {
            $psr4Dev = $autoloadDev['psr-4'] ?? [];
            if (is_array($psr4Dev)) {
                $this->loadPsr4($psr4Dev, $projectRoot);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $psr4
     */
    private function loadPsr4(array $psr4, string $basePath): void
    {
        foreach ($psr4 as $prefix => $paths) {
            if (!is_string($prefix)) {
                continue;
            }
            $prefix = rtrim($prefix, '\\') . '\\';

            if (!isset($this->psr4Mappings[$prefix])) {
                $this->psr4Mappings[$prefix] = [];
            }

            if (is_string($paths)) {
                $this->psr4Mappings[$prefix][] = $basePath . '/' . rtrim($paths, '/');
            } elseif (is_array($paths)) {
                foreach ($paths as $path) {
                    if (is_string($path)) {
                        $this->psr4Mappings[$prefix][] = $basePath . '/' . rtrim($path, '/');
                    }
                }
            }
        }
    }
}
