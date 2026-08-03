<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Composer\Autoload\ClassLoader;

/**
 * The autoload maps Composer generates for a project, held in the same
 * `ClassLoader` Composer itself uses so the data lives in exactly one place.
 *
 * These are what make enumerating `vendor/` affordable. A PSR-4 prefix maps a
 * namespace onto a directory, so the contents of a namespace can be listed by
 * reading a directory rather than by parsing every file beneath it — and only
 * for the namespace actually being looked at.
 *
 * A project with no `vendor/` directory (or none installed yet) yields empty
 * maps rather than an error; the rest of the server keeps working.
 */
final class ComposerAutoloadMap
{
    private readonly ClassLoader $loader;

    /** @var list<string> */
    private readonly array $files;

    /**
     * @param array<string, list<string>> $psr4 Namespace prefix -> directories
     * @param array<string, list<string>> $psr0 Namespace prefix -> directories
     * @param array<string, string> $classMap Fully qualified name -> file
     * @param list<string> $files Files loaded wholesale, for their side effects
     */
    public function __construct(
        array $psr4 = [],
        array $psr0 = [],
        array $classMap = [],
        array $files = [],
    ) {
        $loader = new ClassLoader();

        foreach ($psr4 as $prefix => $directories) {
            $loader->setPsr4($prefix, $directories);
        }
        foreach ($psr0 as $prefix => $directories) {
            $loader->set($prefix, $directories);
        }
        $loader->addClassMap($classMap);

        $this->loader = $loader;
        $this->files = $files;
    }

    public static function fromProjectRoot(string $projectRoot): self
    {
        $composerDir = rtrim($projectRoot, '/') . '/vendor/composer';

        return new self(
            self::loadPrefixes($composerDir . '/autoload_psr4.php'),
            self::loadPrefixes($composerDir . '/autoload_namespaces.php'),
            self::loadClassMap($composerDir . '/autoload_classmap.php'),
            self::loadFiles($composerDir . '/autoload_files.php'),
        );
    }

    /**
     * Split into `[workspace, vendor]` by whether each autoload target lies under
     * $vendorDirectory: the workspace's own code versus its installed dependencies.
     * This backs the fixed backend precedence (RFC 1 §5.3) — an open document, then
     * the workspace, then vendored code — so the two halves resolve as separate
     * {@see \Firehed\PhpLsp\Knowledge\FilesystemBackend}s.
     *
     * A PSR-4/PSR-0 prefix is split per directory, so a prefix mapping to both a
     * project and a vendor path contributes to both halves; classmap entries are
     * split by their file. The union of the two halves is exactly this map, so the
     * split changes precedence, not coverage.
     *
     * @return array{self, self}
     */
    public function partitionByVendorDirectory(string $vendorDirectory): array
    {
        $vendorPrefix = rtrim($vendorDirectory, '/') . '/';
        $isVendor = static fn(string $path): bool => str_starts_with($path, $vendorPrefix);

        [$workspacePsr4, $vendorPsr4] = self::splitPrefixes($this->psr4Prefixes(), $isVendor);
        [$workspacePsr0, $vendorPsr0] = self::splitPrefixes($this->psr0Prefixes(), $isVendor);
        [$workspaceClassMap, $vendorClassMap] = self::splitClassMap($this->classMap(), $isVendor);

        $vendorFiles = array_values(array_filter($this->files, $isVendor));
        $workspaceFiles = array_values(array_filter($this->files, static fn(string $path): bool => !$isVendor($path)));

        return [
            new self($workspacePsr4, $workspacePsr0, $workspaceClassMap, $workspaceFiles),
            new self($vendorPsr4, $vendorPsr0, $vendorClassMap, $vendorFiles),
        ];
    }

    /**
     * The `autoload.files` set: files Composer loads wholesale rather than by name,
     * which is where a project's functions and constants are declared.
     *
     * Unlike PSR-4, this is not a name -> file map — it cannot be, because functions
     * and constants have no such map (Plan 0002 §3). It is an explicit, usually tiny
     * list, which is what makes deriving one by parsing it affordable.
     *
     * @return list<string>
     */
    public function autoloadFiles(): array
    {
        return $this->files;
    }

    /**
     * The populated loader, for name -> file lookup via `findFile()`.
     */
    public function classLoader(): ClassLoader
    {
        return $this->loader;
    }

    /**
     * @return array<string, list<string>>
     */
    public function psr4Prefixes(): array
    {
        return self::withFallback($this->loader->getPrefixesPsr4(), $this->loader->getFallbackDirsPsr4());
    }

    /**
     * @return array<string, list<string>>
     */
    public function psr0Prefixes(): array
    {
        return self::withFallback($this->loader->getPrefixes(), $this->loader->getFallbackDirs());
    }

    /**
     * @return array<string, string>
     */
    public function classMap(): array
    {
        return $this->loader->getClassMap();
    }

    /**
     * Split prefix directories into `[workspace, vendor]` per directory, so a prefix
     * with directories in both halves appears in both.
     *
     * @param array<string, list<string>> $prefixes
     * @param callable(string): bool $isVendor
     * @return array{array<string, list<string>>, array<string, list<string>>}
     */
    private static function splitPrefixes(array $prefixes, callable $isVendor): array
    {
        $workspace = [];
        $vendor = [];

        foreach ($prefixes as $prefix => $directories) {
            foreach ($directories as $directory) {
                if ($isVendor($directory)) {
                    $vendor[$prefix][] = $directory;
                } else {
                    $workspace[$prefix][] = $directory;
                }
            }
        }

        return [$workspace, $vendor];
    }

    /**
     * @param array<string, string> $classMap
     * @param callable(string): bool $isVendor
     * @return array{array<string, string>, array<string, string>}
     */
    private static function splitClassMap(array $classMap, callable $isVendor): array
    {
        $workspace = [];
        $vendor = [];

        foreach ($classMap as $fqn => $file) {
            if ($isVendor($file)) {
                $vendor[$fqn] = $file;
            } else {
                $workspace[$fqn] = $file;
            }
        }

        return [$workspace, $vendor];
    }

    /**
     * A root-namespace mapping (`"": ["src"]`) is a fallback directory in
     * Composer's loader, not a prefix, so it is absent from the prefix accessors.
     * Fold it back to the `''` prefix so enumeration sees one uniform shape.
     *
     * @param array<string, list<string>> $prefixes
     * @param list<string> $fallbackDirectories
     * @return array<string, list<string>>
     */
    private static function withFallback(array $prefixes, array $fallbackDirectories): array
    {
        if ($fallbackDirectories !== []) {
            $prefixes[''] = $fallbackDirectories;
        }

        return $prefixes;
    }

    /**
     * These files are generated, but they are still data read from disk in a
     * project we do not control, so their shape is checked rather than assumed.
     *
     * @return array<string, list<string>>
     */
    private static function loadPrefixes(string $file): array
    {
        $prefixes = [];

        foreach (self::load($file) as $prefix => $directories) {
            if (!is_string($prefix) || !is_array($directories)) {
                continue;
            }

            $prefixes[$prefix] = array_values(array_filter($directories, 'is_string'));
        }

        return $prefixes;
    }

    /**
     * Composer keys the generated file by a content hash, which identifies nothing a
     * consumer needs; the paths are taken as a plain list.
     *
     * @return list<string>
     */
    private static function loadFiles(string $file): array
    {
        $files = [];

        foreach (self::load($file) as $path) {
            if (is_string($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private static function loadClassMap(string $file): array
    {
        $classMap = [];

        foreach (self::load($file) as $fqn => $path) {
            if (is_string($fqn) && is_string($path)) {
                $classMap[$fqn] = $path;
            }
        }

        return $classMap;
    }

    /**
     * @return array<mixed, mixed>
     */
    private static function load(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }

        $contents = require $file;

        if (!is_array($contents)) {
            // @codeCoverageIgnoreStart
            throw new \LogicException("Composer autoload file did not return an array: $file");
            // @codeCoverageIgnoreEnd
        }

        return $contents;
    }
}
