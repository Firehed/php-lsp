<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Composer\Autoload\ClassLoader;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfoInterface;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Parser\SourceFileReader;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;

/**
 * A {@see SymbolSourceInterface} over PHP files on disk, resolved through Composer's
 * autoload maps: the workspace's own code and vendored dependencies alike, with the
 * precedence Composer's own autoloader applies between them.
 *
 * Lookup locates the file for a name and parses that one file — no
 * `vendor/` pre-index (RFC 1 §3, lazy-first). Nothing is remembered here: a
 * {@see CachingSymbolSource} in front of this backend holds an answer while its
 * file is unchanged and drops it when the file changes.
 *
 * Namespace enumeration is a directory listing through the same autoload map:
 * PSR-4 and PSR-0 prefixes map a namespace to a directory whose contents are
 * scanned, and the classmap is turned into a namespace index on first use.
 *
 * Prefix search is empty: PSR-4, PSR-0 and the classmap have no name -> file
 * map for a bare prefix, so project-wide search over disk is the deferred
 * workspace-index scope (RFC 1 §3). Names declared in `autoload.files`
 * entries — where a bare-prefix search *is* affordable — are covered by
 * {@see AutoloadFilesBackend} in its own row.
 */
final class ComposerMapBackend implements SymbolSourceInterface
{
    use LooksUpByKindTrait;

    /** @var array<string, NamespaceContents>|null Lowercase namespace -> contents */
    private ?array $classMapIndex = null;

    public function __construct(
        private readonly ComposerAutoloadMap $map,
        private readonly SyntaxSourceInterface $parser,
        private readonly SourceFileReader $reader,
        private readonly DeclarationSymbolInfoFactory $infoFactory,
        private readonly DeclarationScanner $scanner,
    ) {
    }

    public function childrenOf(NamespaceName $namespace): NamespaceContents
    {
        $path = $namespace->path;

        return NamespaceContents::merge([
            $this->fromPrefixes($this->map->psr4Prefixes(), $path, nestsPrefix: false),
            $this->fromPrefixes($this->map->psr0Prefixes(), $path, nestsPrefix: true),
            $this->fromClassMap($path),
        ]);
    }

    /**
     * @return list<Symbol>
     */
    public function search(string $prefix, NameKind $kind): array
    {
        return [];
    }

    /**
     * PSR-4 strips the prefix from the path (`App\Model` under prefix `App\` is
     * `<dir>/Model`); PSR-0 does not (`Psr0\Sub` under prefix `Psr0` is
     * `<dir>/Psr0/Sub`).
     *
     * @param array<string, list<string>> $prefixes
     */
    private function fromPrefixes(array $prefixes, string $namespace, bool $nestsPrefix): NamespaceContents
    {
        $childNamespaces = [];
        $symbols = [];

        foreach ($prefixes as $prefix => $directories) {
            $prefixNamespace = trim($prefix, '\\');

            // The namespace sits above the prefix: the prefix itself names the
            // child, and no directory needs to be read.
            $below = (new NamespaceName($prefixNamespace))->relativeTo(new NamespaceName($namespace));
            if ($below !== null) {
                $child = NamespaceName::join($namespace, NamespaceName::firstSegment($below));
                $childNamespaces[(new NamespaceName($child))->normalize()] = $child;
                continue;
            }

            // The namespace is at or below the prefix: read the directory it maps to.
            $withinPrefix = (new NamespaceName($prefixNamespace))->equals(new NamespaceName($namespace))
                ? ''
                : (new NamespaceName($namespace))->relativeTo(new NamespaceName($prefixNamespace));
            if ($withinPrefix === null) {
                continue;
            }

            // PSR-0 nests the prefix inside the directory, so the path is the
            // whole namespace; PSR-4 strips it, so the path is only the part
            // below the prefix.
            $pathSegments = $nestsPrefix
                ? self::segments($namespace)
                : self::segments($withinPrefix);
            $rootNamespace = $nestsPrefix ? '' : $prefixNamespace;

            foreach ($directories as $directory) {
                $resolved = self::resolveDirectory($directory, $pathSegments);
                if ($resolved === null) {
                    continue;
                }
                [$path, $realSegments] = $resolved;

                // The namespace as it is really spelled: the prefix's own casing,
                // then the casing of the directories actually on disk.
                $canonical = NamespaceName::join($rootNamespace, ...$realSegments);

                $contents = self::readDirectory($path, $canonical);
                foreach ($contents->childNamespaces as $child) {
                    $childNamespaces[(new NamespaceName($child))->normalize()] = $child;
                }
                foreach ($contents->symbols as $symbol) {
                    $symbols[$symbol->key()] = $symbol;
                }
            }
        }

        return new NamespaceContents(array_values($childNamespaces), array_values($symbols));
    }

    private function fromClassMap(string $namespace): NamespaceContents
    {
        $this->classMapIndex ??= NamespaceContents::indexByNamespace(array_map(
            static fn(string $fqn): \Firehed\PhpLsp\Index\CatalogSymbol
                => new \Firehed\PhpLsp\Index\CatalogSymbol($fqn, NameKind::ClassLike),
            array_keys($this->map->classMap()),
        ));

        return $this->classMapIndex[(new NamespaceName($namespace))->normalize()] ?? new NamespaceContents();
    }

    /**
     * Composer's own `ClassLoader` is rebuilt from the map on every lookup: it
     * remembers every miss for its whole lifetime and has no reset, so a held
     * instance would never see a file created after the first miss. Memoizing
     * hits is the caching decorator's job.
     */
    private function locate(QualifiedName $name, NameKind $kind): ?string
    {
        if (!$kind->isClassLike()) {
            return null;
        }

        $loader = new ClassLoader();
        foreach ($this->map->psr4Prefixes() as $prefix => $directories) {
            $loader->setPsr4($prefix, $directories);
        }
        foreach ($this->map->psr0Prefixes() as $prefix => $directories) {
            $loader->set($prefix, $directories);
        }
        $loader->addClassMap($this->map->classMap());

        $file = $loader->findFile($name->fullyQualifiedName());

        return $file !== false ? $file : null;
    }

    private function lookup(QualifiedName $name, NameKind $kind): ?SymbolInfoInterface
    {
        $filePath = $this->locate($name, $kind);
        if ($filePath === null) {
            return null;
        }

        $declarations = $this->scanner->scanFile($filePath, $this->reader, $this->parser);

        return $this->infoFactory->fromDeclarations($declarations, $name, $kind, $filePath);
    }

    /**
     * Subdirectories are child namespaces; `.php` files declare the class-like
     * they are named after.
     */
    private static function readDirectory(string $path, string $namespace): NamespaceContents
    {
        $entries = scandir($path);
        if ($entries === false) {
            // @codeCoverageIgnoreStart
            return new NamespaceContents();
            // @codeCoverageIgnoreEnd
        }

        $childNamespaces = [];
        $symbols = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (is_dir($path . '/' . $entry)) {
                $childNamespaces[] = NamespaceName::join($namespace, $entry);
                continue;
            }

            if (!str_ends_with($entry, '.php')) {
                continue;
            }

            $symbols[] = new \Firehed\PhpLsp\Index\CatalogSymbol(
                NamespaceName::join($namespace, basename($entry, '.php')),
                NameKind::ClassLike,
            );
        }

        return new NamespaceContents($childNamespaces, $symbols);
    }

    /**
     * Walk a namespace's segments down from an autoload root, matching directory
     * names case-insensitively (as PHP namespaces are) and reporting the names
     * as they are actually spelled on disk.
     *
     * @param list<string> $segments
     * @return array{string, list<string>}|null
     */
    private static function resolveDirectory(string $baseDirectory, array $segments): ?array
    {
        $path = $baseDirectory;
        $realSegments = [];

        foreach ($segments as $segment) {
            $entries = is_dir($path) ? scandir($path) : false;
            if ($entries === false) {
                return null;
            }

            $match = null;
            foreach ($entries as $entry) {
                if ((new NamespaceName($entry))->equals(new NamespaceName($segment)) && is_dir($path . '/' . $entry)) {
                    $match = $entry;
                    break;
                }
            }

            if ($match === null) {
                return null;
            }

            $path .= '/' . $match;
            $realSegments[] = $match;
        }

        return is_dir($path) ? [$path, $realSegments] : null;
    }

    /**
     * @return list<string>
     */
    private static function segments(string $namespace): array
    {
        return $namespace === '' ? [] : explode('\\', $namespace);
    }
}
