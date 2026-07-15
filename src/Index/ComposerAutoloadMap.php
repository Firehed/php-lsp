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

    /**
     * @param array<string, list<string>> $psr4 Namespace prefix -> directories
     * @param array<string, list<string>> $psr0 Namespace prefix -> directories
     * @param array<string, string> $classMap Fully qualified name -> file
     */
    public function __construct(
        array $psr4 = [],
        array $psr0 = [],
        array $classMap = [],
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
    }

    public static function fromProjectRoot(string $projectRoot): self
    {
        $composerDir = rtrim($projectRoot, '/') . '/vendor/composer';

        return new self(
            self::loadPrefixes($composerDir . '/autoload_psr4.php'),
            self::loadPrefixes($composerDir . '/autoload_namespaces.php'),
            self::loadClassMap($composerDir . '/autoload_classmap.php'),
        );
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
