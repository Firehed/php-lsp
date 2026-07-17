<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Protocol\Range;
use Firehed\PhpLsp\Resolution\CodeResolver;
use Firehed\PhpLsp\Resolution\NameContext;
use Firehed\PhpLsp\Resolution\NameKind;
use Firehed\PhpLsp\Utility\NamespacePath;

/**
 * Produces namespace-navigation items from the {@see NamespaceCatalog}: the child
 * namespaces of a namespace, as `Module` nodes the user steps into one segment at
 * a time, plus the class-likes declared directly in it.
 *
 * This is the discovery half of #308 made navigable — vendor and built-in symbols
 * become reachable by walking the tree (`\Ps` → `Psr\` → `Psr\Log\`) rather than
 * by flattening every name into a short-name index.
 *
 * A child namespace with only a few members is inlined instead of offered as a
 * node, so the user need not step through it to reach a handful of entries.
 *
 * @phpstan-import-type CompletionItem from CompletionItemFactory
 */
final class NamespaceCandidates
{
    // A child namespace with this many members or fewer is inlined rather than
    // offered as a node. A starting point, expected to be tuned with real use.
    private const INLINE_THRESHOLD = 5;

    public function __construct(
        private readonly NamespaceCatalog $catalog,
        private readonly CodeResolver $codeResolver,
    ) {
    }

    /**
     * Navigate the namespace tree for a class-position $prefix, dispatching on how
     * the name is rooted so name resolution stays out of the handler:
     *
     * - absolute (`\Ps`) walks from the global namespace;
     * - a bare relative name (`Env`) descends into imports and children of the
     *   current namespace, inlining a small target and noding a large one, exactly
     *   as the absolute form would once the first segment is resolved;
     * - a qualified relative name (`Env\R`) resolves its first segment through the
     *   imports, else the current namespace, and walks from there.
     *
     * Every case inserts leaf-relative (see {@see find()}), so the typed segments
     * stand and are never duplicated.
     *
     * @return list<CompletionItem>
     */
    public function navigate(
        string $prefix,
        NameContext $context,
        int $line,
        int $character,
        ClassCandidateFilter $filter,
    ): array {
        if (str_starts_with($prefix, '\\')) {
            $qualified = substr($prefix, 1);

            return $this->find(
                NamespacePath::namespaceOf($qualified),
                NamespacePath::shortNameOf($qualified),
                $line,
                $character,
                $filter,
            );
        }

        if (!str_contains($prefix, '\\')) {
            return $this->descend($context, $prefix, $line, $character, $filter);
        }

        $alias = NamespacePath::firstSegment($prefix);
        $base = array_key_exists($alias, $context->classImports)
            ? $context->classImports[$alias]
            : NamespacePath::join($context->namespace, $alias);
        $rest = substr($prefix, strlen($alias) + 1);

        return $this->find(
            NamespacePath::join($base, NamespacePath::namespaceOf($rest)),
            NamespacePath::shortNameOf($rest),
            $line,
            $character,
            $filter,
        );
    }

    /**
     * The child namespaces of $namespace, plus the class-likes declared in it that
     * are valid in the target position — matched by their next segment against
     * $prefix.
     *
     * @return list<CompletionItem>
     */
    public function find(
        string $namespace,
        string $prefix,
        int $line,
        int $character,
        ClassCandidateFilter $filter,
    ): array {
        $contents = $this->catalog->childrenOf($namespace);
        // The partial segment the user is typing; a selection replaces just it.
        $replaceRange = Range::onLine($line, $character - strlen($prefix), $character);

        $items = [];
        foreach ($contents->childNamespaces as $child) {
            $segment = NamespacePath::shortNameOf($child);
            if (!PrefixMatcher::matches($segment, $prefix)) {
                continue;
            }
            $items = array_merge($items, $this->offerChildNamespace($child, $segment, $filter, $replaceRange));
        }
        // The classes declared directly in the navigated namespace, discovered on
        // disk through the catalog. The reference is the leaf name — the earlier
        // segments are already typed — and the textEdit replaces the partial one.
        foreach ($contents->symbols as $symbol) {
            if (!PrefixMatcher::matches($symbol->shortName(), $prefix)) {
                continue;
            }
            $item = $this->offerSymbol($symbol, $symbol->shortName(), null, $filter, $replaceRange);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $this->ranked($items);
    }

    /**
     * Candidates for a bare relative name: each `use` import or child of the current
     * namespace whose name the typed $prefix begins and which is itself a navigable
     * namespace. Each is offered through the same {@see offerChildNamespace()} as
     * absolute navigation, so a small target inlines and a large one is a node —
     * identically, whichever way the namespace was reached. Imports win a name clash
     * with a current-namespace child, matching PHP name resolution.
     *
     * @return list<CompletionItem>
     */
    public function descend(
        NameContext $context,
        string $prefix,
        int $line,
        int $character,
        ClassCandidateFilter $filter,
    ): array {
        $range = Range::onLine($line, $character - strlen($prefix), $character);

        $targets = [];
        foreach ($this->catalog->childrenOf($context->namespace)->childNamespaces as $child) {
            $targets[NamespacePath::shortNameOf($child)] = $child;
        }
        foreach ($context->classImports as $alias => $fqcn) {
            $targets[$alias] = $fqcn;
        }

        $items = [];
        foreach ($targets as $reference => $fqcn) {
            if (!PrefixMatcher::matches($reference, $prefix) || !$this->isNavigable($fqcn)) {
                continue;
            }
            $items = array_merge($items, $this->offerChildNamespace($fqcn, $reference, $filter, $range));
        }

        return $this->ranked($items);
    }

    private function isNavigable(string $namespace): bool
    {
        $contents = $this->catalog->childrenOf($namespace);

        return count($contents->childNamespaces) > 0 || count($contents->symbols) > 0;
    }

    /**
     * Rank directly-insertable symbols above namespace nodes: a class you can use
     * now beats a prefix you would keep typing. Within each group, order by label.
     *
     * @param list<CompletionItem> $items
     * @return list<CompletionItem>
     */
    private function ranked(array $items): array
    {
        foreach ($items as $index => $item) {
            $isNode = ($item['kind'] ?? null) === CompletionItemKind::Module->value;
            $items[$index]['sortText'] = ($isNode ? '1' : '0') . '_' . $item['label'];
        }

        return $items;
    }

    /**
     * A child namespace with few members is inlined — its contents offered
     * directly, qualified by the segment — so the user need not step through it; a
     * larger one is offered as a node to navigate into. A same-named class is a
     * separate symbol of the parent, so it is always offered alongside either form.
     *
     * Inlining is one level: an inlined namespace's own child namespaces become
     * qualified nodes to step into, not a further recursion.
     *
     * @return list<CompletionItem>
     */
    private function offerChildNamespace(
        string $child,
        string $segment,
        ClassCandidateFilter $filter,
        Range $range,
    ): array {
        // One catalog lookup per matching child, to decide node-vs-inline. This is
        // discovery, which the handler's cap does not bound — the cap limits output,
        // not how many children are inspected. The cost is a directory listing per
        // child, memoised by CachedNamespaceCatalog for the stable (vendor/built-in)
        // sources, so a namespace is inspected at most once per session.
        $contents = $this->catalog->childrenOf($child);
        $elementCount = count($contents->childNamespaces) + count($contents->symbols);
        if ($elementCount === 0 || $elementCount > self::INLINE_THRESHOLD) {
            return [CompletionItemFactory::forNamespace($segment, $child, $range)];
        }

        $items = [];
        foreach ($contents->childNamespaces as $grandchild) {
            $reference = $segment . '\\' . NamespacePath::shortNameOf($grandchild);
            $items[] = CompletionItemFactory::forNamespace($reference, $grandchild, $range);
        }
        foreach ($contents->symbols as $symbol) {
            $reference = $segment . '\\' . $symbol->shortName();
            // The user reaches an inlined entry by typing the parent segment, so it
            // filters on the qualified reference rather than its leaf.
            $item = $this->offerSymbol($symbol, $reference, $reference, $filter, $range);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * A single class-like completion item, or null when the symbol is not a valid
     * class-like for the position. Validity is the same rule the index and imports
     * use, preceded by an existence gate that drops directory-listing phantoms (a
     * `functions.php` surfaced as a coarse class-like) before the optimistic
     * position filter would let them through.
     *
     * @return ?CompletionItem
     */
    private function offerSymbol(
        CatalogSymbol $symbol,
        string $reference,
        ?string $filterText,
        ClassCandidateFilter $filter,
        Range $range,
    ): ?array {
        if ($symbol->kind !== NameKind::ClassLike) {
            return null;
        }
        /** @var class-string $fqcn */
        $fqcn = $symbol->fullyQualifiedName;
        $className = new ClassName($fqcn);
        if (!$this->codeResolver->isClassLike($className)) {
            return null;
        }
        if (!$filter->accepts($className, $this->codeResolver)) {
            return null;
        }

        return CompletionItemFactory::forClass($reference, $fqcn, $range, $filterText);
    }
}
