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
    /** @var array<string, list<string>> Namespace prefix (trailing separator) -> directories */
    private array $psr4 = [];

    /** @var array<string, list<string>> Namespace prefix -> directories */
    private array $psr0 = [];

    /** @var array<string, string> Fully qualified name -> file */
    private array $classMap = [];

    public function __construct(string $projectRoot)
    {
        $composerDir = rtrim($projectRoot, '/') . '/vendor/composer';

        /** @var array<string, list<string>> */
        $this->psr4 = self::load($composerDir . '/autoload_psr4.php');
        /** @var array<string, list<string>> */
        $this->psr0 = self::load($composerDir . '/autoload_namespaces.php');
        /** @var array<string, string> */
        $this->classMap = self::load($composerDir . '/autoload_classmap.php');
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
     * @return array<string, mixed>
     */
    private static function load(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }

        /** @var array<string, mixed> */
        return require $file;
    }
}
