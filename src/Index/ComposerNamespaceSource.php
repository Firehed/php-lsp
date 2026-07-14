<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Resolution\NameKind;
use Firehed\PhpLsp\Utility\NamespacePath;

/**
 * Discovers symbols through Composer's autoload maps, without parsing or
 * pre-indexing `vendor/`.
 *
 * Under PSR-4 and PSR-0 a namespace maps to a directory, so the contents of a
 * namespace are a directory listing: subdirectories are child namespaces, and
 * `.php` files are class-likes named after the file. Only the directories for
 * the namespace being asked about are read, so navigating to `Psr\Log\` costs
 * one `scandir` no matter how large the rest of `vendor/` is.
 *
 * Namespaces above a prefix are known from the prefix string alone — a prefix of
 * `Psr\Http\Message\` says `Psr\Http` exists without any disk access at all.
 *
 * The classmap is the exception: it maps names to files with no namespace
 * structure, so it is turned into a namespace index once, on first use.
 */
final class ComposerNamespaceSource implements NamespaceCatalog
{
    /** @var array<string, NamespaceContents>|null Lowercase namespace -> contents */
    private ?array $classMapIndex = null;

    public function __construct(
        private readonly ComposerAutoloadMap $map,
    ) {
    }

    public function childrenOf(string $namespace): NamespaceContents
    {
        return NamespaceContents::merge([
            $this->fromPrefixes($this->map->psr4Prefixes(), $namespace, nestsPrefix: false),
            $this->fromPrefixes($this->map->psr0Prefixes(), $namespace, nestsPrefix: true),
            $this->fromClassMap($namespace),
        ]);
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
            $below = NamespacePath::relativeTo($prefixNamespace, $namespace);
            if ($below !== null) {
                $child = NamespacePath::join($namespace, NamespacePath::firstSegment($below));
                $childNamespaces[strtolower($child)] = $child;
                continue;
            }

            // The namespace is at or below the prefix: read the directory it maps to.
            $withinPrefix = NamespacePath::equals($prefixNamespace, $namespace)
                ? ''
                : NamespacePath::relativeTo($namespace, $prefixNamespace);
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
                $canonical = NamespacePath::join($rootNamespace, ...$realSegments);

                $contents = self::readDirectory($path, $canonical);
                foreach ($contents->childNamespaces as $child) {
                    $childNamespaces[strtolower($child)] = $child;
                }
                foreach ($contents->symbols as $symbol) {
                    $symbols[strtolower($symbol->fullyQualifiedName)] = $symbol;
                }
            }
        }

        return new NamespaceContents(array_values($childNamespaces), array_values($symbols));
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
                if (strcasecmp($entry, $segment) === 0 && is_dir($path . '/' . $entry)) {
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
                $childNamespaces[] = NamespacePath::join($namespace, $entry);
                continue;
            }

            if (!str_ends_with($entry, '.php')) {
                continue;
            }

            $symbols[] = new CatalogSymbol(
                NamespacePath::join($namespace, basename($entry, '.php')),
                NameKind::ClassLike,
            );
        }

        return new NamespaceContents($childNamespaces, $symbols);
    }

    private function fromClassMap(string $namespace): NamespaceContents
    {
        $this->classMapIndex ??= self::indexClassMap($this->map->classMap());

        return $this->classMapIndex[strtolower($namespace)] ?? new NamespaceContents();
    }

    /**
     * @param array<string, string> $classMap
     * @return array<string, NamespaceContents>
     */
    private static function indexClassMap(array $classMap): array
    {
        $childNamespaces = [];
        $symbols = [];

        foreach (array_keys($classMap) as $fqn) {
            $namespace = NamespacePath::namespaceOf($fqn);
            $symbols[strtolower($namespace)][] = new CatalogSymbol($fqn, NameKind::ClassLike);

            foreach (NamespacePath::ancestors($namespace) as $parent => $child) {
                $childNamespaces[strtolower($parent)][strtolower($child)] = $child;
            }
        }

        $contents = [];
        foreach (array_keys($childNamespaces + $symbols) as $namespace) {
            $contents[$namespace] = new NamespaceContents(
                array_values($childNamespaces[$namespace] ?? []),
                $symbols[$namespace] ?? [],
            );
        }

        return $contents;
    }
}
