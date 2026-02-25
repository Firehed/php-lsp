<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

final class ComposerClassLocator
{
    /** @var array<string, list<string>> namespace prefix => directories */
    private array $psr4Mappings = [];

    public function __construct(string $projectRoot)
    {
        $projectRoot = rtrim($projectRoot, '/');
        $this->loadFromComposerAutoload($projectRoot);
    }

    public function locateClass(string $fullyQualifiedName): ?string
    {
        // Sort by prefix length descending for most specific match first
        $prefixes = array_keys($this->psr4Mappings);
        usort($prefixes, fn($a, $b) => strlen($b) <=> strlen($a));

        foreach ($prefixes as $prefix) {
            if (!str_starts_with($fullyQualifiedName, $prefix)) {
                continue;
            }

            $relativeClass = substr($fullyQualifiedName, strlen($prefix));
            $relativePath = str_replace('\\', '/', $relativeClass) . '.php';

            foreach ($this->psr4Mappings[$prefix] as $directory) {
                $fullPath = $directory . '/' . $relativePath;
                if (file_exists($fullPath)) {
                    return $fullPath;
                }
            }
        }

        return null;
    }

    private function loadFromComposerAutoload(string $projectRoot): void
    {
        // Use Composer's generated autoload which includes all vendor mappings
        $autoloadFile = $projectRoot . '/vendor/composer/autoload_psr4.php';

        if (file_exists($autoloadFile)) {
            $mappings = require $autoloadFile;
            if (is_array($mappings)) {
                foreach ($mappings as $prefix => $dirs) {
                    if (!is_string($prefix) || !is_array($dirs)) {
                        continue;
                    }
                    $prefix = rtrim($prefix, '\\') . '\\';
                    $this->psr4Mappings[$prefix] = [];
                    foreach ($dirs as $dir) {
                        if (is_string($dir)) {
                            $this->psr4Mappings[$prefix][] = rtrim($dir, '/');
                        }
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
