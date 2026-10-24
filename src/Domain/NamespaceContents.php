<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

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
     * `Random`, and `Random` a child of the global namespace. Of a symbol
     * listed more than once, the first listing is kept.
     *
     * @param iterable<CatalogSymbol> $symbols
     * @return array<string, self> Lowercase namespace -> contents
     */
    public static function indexByNamespace(iterable $symbols): array
    {
        $childNamespaces = [];
        $symbolsByNamespace = [];

        foreach ($symbols as $symbol) {
            $namespace = new NamespaceName(NamespaceName::namespaceOf($symbol->fullyQualifiedName));
            $symbolsByNamespace[$namespace->normalize()][] = $symbol;

            foreach ($namespace->ancestors() as $parent => $child) {
                $childNamespaces[(new NamespaceName($parent))->normalize()][] = $child;
            }
        }

        $contents = [];
        foreach (array_keys($childNamespaces + $symbolsByNamespace) as $namespace) {
            $contents[$namespace] = self::firstListings(
                $childNamespaces[$namespace] ?? [],
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
     * also on disk under a PSR-4 prefix, and both sources will report it. The
     * first source to report a name wins, so sources are passed in order of
     * authority — the workspace knows how its own symbols are spelled better
     * than a directory listing does.
     *
     * @param list<NamespaceContents> $contents
     */
    public static function merge(array $contents): self
    {
        return self::firstListings(
            array_merge(...array_map(static fn (self $part): array => $part->childNamespaces, $contents)),
            array_merge(...array_map(static fn (self $part): array => $part->symbols, $contents)),
        );
    }

    /**
     * Contents listing each namespace and each symbol once. Of several
     * listings of one, under its case rule, the first is kept.
     *
     * @param list<string> $namespaces
     * @param list<CatalogSymbol> $symbols
     */
    private static function firstListings(array $namespaces, array $symbols): self
    {
        $firstNamespaces = [];
        foreach ($namespaces as $namespace) {
            $firstNamespaces[(new NamespaceName($namespace))->normalize()] ??= $namespace;
        }
        $firstSymbols = [];
        foreach ($symbols as $symbol) {
            $firstSymbols[$symbol->key()] ??= $symbol;
        }

        return new self(array_values($firstNamespaces), array_values($firstSymbols));
    }
}
