<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

final class ComposerClassLocator
{
    /** @var array<string, string> namespace prefix => directory */
    private array $psr4Mappings = [];
    private string $basePath;

    public function __construct(string $projectRoot)
    {
        $this->basePath = rtrim($projectRoot, '/');
        $this->loadComposerJson();
    }

    public function locateClass(string $fullyQualifiedName): ?string
    {
        // Try PSR-4 mappings
        foreach ($this->psr4Mappings as $prefix => $directory) {
            if (str_starts_with($fullyQualifiedName, $prefix)) {
                $relativeClass = substr($fullyQualifiedName, strlen($prefix));
                $relativePath = str_replace('\\', '/', $relativeClass) . '.php';
                $fullPath = $this->basePath . '/' . $directory . $relativePath;

                if (file_exists($fullPath)) {
                    return $fullPath;
                }
            }
        }

        return null;
    }

    private function loadComposerJson(): void
    {
        $composerPath = $this->basePath . '/composer.json';
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
                $this->loadPsr4($psr4);
            }
        }

        // Also load from autoload-dev for test classes
        $autoloadDev = $data['autoload-dev'] ?? [];
        if (is_array($autoloadDev)) {
            $psr4Dev = $autoloadDev['psr-4'] ?? [];
            if (is_array($psr4Dev)) {
                $this->loadPsr4($psr4Dev);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $psr4
     */
    private function loadPsr4(array $psr4): void
    {
        foreach ($psr4 as $prefix => $paths) {
            if (!is_string($prefix)) {
                continue;
            }
            // Normalize prefix to end with backslash
            $prefix = rtrim($prefix, '\\') . '\\';

            // Paths can be string or array
            if (is_string($paths)) {
                $this->psr4Mappings[$prefix] = rtrim($paths, '/') . '/';
            } elseif (is_array($paths)) {
                foreach ($paths as $path) {
                    if (is_string($path)) {
                        $this->psr4Mappings[$prefix] = rtrim($path, '/') . '/';
                    }
                }
            }
        }
    }
}
