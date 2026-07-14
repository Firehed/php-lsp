<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Utility\NamespacePath;

/**
 * The immediate children of a namespace: the namespaces directly beneath it and
 * the symbols declared directly in it.
 *
 * Only one level deep, which is what makes discovery affordable — navigating to
 * `Psr\` does not require knowing anything about `Psr\Log\LoggerInterface`.
 *
 * Namespaces are listed by fully qualified name; the trailing segment is a
 * presentation concern.
 */
final readonly class NamespaceContents
{
    /**
     * @param list<string> $childNamespaces
     * @param list<CatalogSymbol> $symbols
     */
    public function __construct(
        public array $childNamespaces = [],
        public array $symbols = [],
    ) {
    }

    /**
     * Group symbols by the namespace they are declared in.
     *
     * Every ancestor namespace on the way to a symbol is registered too, so an
     * intermediate namespace that declares nothing itself is still reachable:
     * `Random\Engine\Xoshiro256StarStar` makes `Random\Engine` a child of
     * `Random`, and `Random` a child of the global namespace.
     *
     * @param iterable<CatalogSymbol> $symbols
     * @return array<string, self> Lowercase namespace -> contents
     */
    public static function indexByNamespace(iterable $symbols): array
    {
        $childNamespaces = [];
        $symbolsByNamespace = [];

        foreach ($symbols as $symbol) {
            $namespace = NamespacePath::namespaceOf($symbol->fullyQualifiedName);
            $symbolsByNamespace[strtolower($namespace)][] = $symbol;

            foreach (NamespacePath::ancestors($namespace) as $parent => $child) {
                $childNamespaces[strtolower($parent)][strtolower($child)] ??= $child;
            }
        }

        $contents = [];
        foreach (array_keys($childNamespaces + $symbolsByNamespace) as $namespace) {
            $contents[$namespace] = new self(
                array_values($childNamespaces[$namespace] ?? []),
                $symbolsByNamespace[$namespace] ?? [],
            );
        }

        return $contents;
    }

    /**
     * Combine the contents reported by several sources, discarding names that
     * more than one of them reports.
     *
     * Overlap is normal rather than exceptional: a class in an open document is
     * also on disk under a PSR-4 prefix, and both sources will report it.
     *
     * @param list<NamespaceContents> $contents
     */
    public static function merge(array $contents): self
    {
        $namespaces = [];
        $symbols = [];

        foreach ($contents as $part) {
            foreach ($part->childNamespaces as $namespace) {
                $namespaces[strtolower($namespace)] = $namespace;
            }
            foreach ($part->symbols as $symbol) {
                $symbols[strtolower($symbol->fullyQualifiedName)] ??= $symbol;
            }
        }

        return new self(array_values($namespaces), array_values($symbols));
    }
}
