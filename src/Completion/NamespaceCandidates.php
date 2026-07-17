<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Protocol\Range;
use Firehed\PhpLsp\Resolution\CodeResolver;
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
     * The child namespaces of $namespace, plus the class-likes declared in it that
     * are valid in the target position — matched by their next segment against
     * $prefix.
     *
     * References default to the child's leaf name, for absolute navigation where the
     * earlier segments are literally typed (`\Psr\Log\`). $referenceBase prepends an
     * alias instead, for an imported prefix reached through a `use` (`Env\Repository`
     * for a class in `App\Model\Env` imported as `Env`); $replaceRange then covers the
     * whole typed alias so the insertion never duplicates it (#339). When omitted, the
     * range is the partial leaf, the absolute-navigation default.
     *
     * @return list<CompletionItem>
     */
    public function find(
        string $namespace,
        string $prefix,
        int $line,
        int $character,
        ClassCandidateFilter $filter,
        string $referenceBase = '',
        ?Range $replaceRange = null,
    ): array {
        $contents = $this->catalog->childrenOf($namespace);
        // The partial segment the user is typing; a selection replaces just it.
        $replaceRange ??= Range::onLine($line, $character - strlen($prefix), $character);

        $items = [];
        foreach ($contents->childNamespaces as $child) {
            $segment = NamespacePath::shortNameOf($child);
            if (!PrefixMatcher::matches($segment, $prefix)) {
                continue;
            }
            $reference = self::qualify($referenceBase, $segment);
            $items = array_merge($items, $this->offerChildNamespace($child, $reference, $filter, $replaceRange));
        }
        // The classes declared directly in the navigated namespace, discovered on
        // disk through the catalog. The reference is the leaf name (or alias-qualified
        // via $referenceBase), and the textEdit replaces the typed span.
        foreach ($contents->symbols as $symbol) {
            if (!PrefixMatcher::matches($symbol->shortName(), $prefix)) {
                continue;
            }
            $reference = self::qualify($referenceBase, $symbol->shortName());
            $item = $this->offerSymbol($symbol, $reference, $filter, $replaceRange);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $this->ranked($items);
    }

    private static function qualify(string $base, string $segment): string
    {
        return $base === '' ? $segment : $base . '\\' . $segment;
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
        string $reference,
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
            return [CompletionItemFactory::forNamespace($reference, $child, $range)];
        }

        $items = [];
        foreach ($contents->childNamespaces as $grandchild) {
            $grandReference = $reference . '\\' . NamespacePath::shortNameOf($grandchild);
            $items[] = CompletionItemFactory::forNamespace($grandReference, $grandchild, $range);
        }
        foreach ($contents->symbols as $symbol) {
            $symbolReference = $reference . '\\' . $symbol->shortName();
            $item = $this->offerSymbol($symbol, $symbolReference, $filter, $range);
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

        // The user reaches a navigation entry by typing its qualified reference, so
        // it filters on that reference rather than on its bare leaf.
        return CompletionItemFactory::forClass($reference, $fqcn, $range, $reference);
    }
}
