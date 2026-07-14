<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

/**
 * The autoload maps Composer generates for a project.
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
    /**
     * @param array<string, list<string>> $psr4 Namespace prefix -> directories
     * @param array<string, list<string>> $psr0 Namespace prefix -> directories
     * @param array<string, string> $classMap Fully qualified name -> file
     */
    public function __construct(
        private readonly array $psr4 = [],
        private readonly array $psr0 = [],
        private readonly array $classMap = [],
    ) {
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
