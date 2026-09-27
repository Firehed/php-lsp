<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * The autoload maps Composer generates for a project, as data. Resolving a name
 * through them is the on-disk symbol backend's job, which runs Composer's own
 * loader over these arrays so the runtime's rules apply verbatim.
 *
 * These are what make enumerating `vendor/` affordable. A PSR-4 prefix maps a
 * namespace onto a directory, so the contents of a namespace can be listed by
 * reading a directory rather than by parsing every file beneath it — and only
 * for the namespace actually being looked at.
 *
 * A project with no `vendor/` directory (or none installed yet) yields empty
 * maps rather than an error; the rest of the server keeps working.
 */
final readonly class ComposerAutoloadMap
{
    /**
     * @param array<string, list<string>> $psr4 Namespace prefix -> directories
     * @param array<string, list<string>> $psr0 Namespace prefix -> directories
     * @param array<string, string> $classMap Fully qualified name -> file
     * @param list<string> $files Files loaded wholesale, for their side effects
     */
    public function __construct(
        private array $psr4 = [],
        private array $psr0 = [],
        private array $classMap = [],
        private array $files = [],
    ) {
    }

    public static function composerDirFor(string $projectRoot): string
    {
        return rtrim($projectRoot, '/') . '/vendor/composer';
    }

    public static function fromProjectRoot(string $projectRoot): self
    {
        $composerDir = self::composerDirFor($projectRoot);

        return new self(
            self::loadPrefixes($composerDir . '/autoload_psr4.php'),
            self::loadPrefixes($composerDir . '/autoload_namespaces.php'),
            self::loadClassMap($composerDir . '/autoload_classmap.php'),
            self::loadFiles($composerDir . '/autoload_files.php'),
        );
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
     * A root-namespace mapping (`"": ["src"]`) is a fallback directory to Composer's
     * loader; here it is the `''` prefix, so enumeration sees one uniform shape.
     *
     * @return array<string, list<string>>
     */
    public function psr4Prefixes(): array
    {
        return $this->psr4;
    }

    /**
     * @return array<string, list<string>>
     */
    public function psr0Prefixes(): array
    {
        return $this->psr0;
    }

    /**
     * @return array<string, string>
     */
    public function classMap(): array
    {
        return $this->classMap;
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
