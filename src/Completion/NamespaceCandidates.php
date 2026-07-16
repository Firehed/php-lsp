<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Protocol\Range;
use Firehed\PhpLsp\Resolution\CodeResolver;
use Firehed\PhpLsp\Resolution\NameKind;
use Firehed\PhpLsp\Utility\NamespacePath;

/**
 * Produces namespace-navigation items from the {@see NamespaceCatalog}: the child
 * namespaces of a namespace, as `Module` nodes the user steps into one segment at
 * a time.
 *
 * This is the discovery half of #308 made navigable — vendor and built-in symbols
 * become reachable by walking the tree (`\Ps` → `Psr\` → `Psr\Log\`) rather than
 * by flattening every name into a short-name index.
 *
 * @phpstan-import-type CompletionItem from CompletionItemFactory
 */
final class NamespaceCandidates
{
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
            if (PrefixMatcher::matches(NamespacePath::shortNameOf($child), $prefix)) {
                $items[] = CompletionItemFactory::forNamespace($child, $replaceRange);
            }
        }
        // The classes declared directly in the navigated namespace, discovered on
        // disk through the catalog. The reference is the leaf name — the earlier
        // segments are already typed — and the textEdit replaces the partial one.
        // Validity in the position is the same rule the index and imports use.
        foreach ($contents->symbols as $symbol) {
            if ($symbol->kind !== NameKind::ClassLike) {
                continue;
            }
            /** @var class-string $fqcn */
            $fqcn = $symbol->fullyQualifiedName;
            if (!PrefixMatcher::matches($symbol->shortName(), $prefix)) {
                continue;
            }
            $className = new ClassName($fqcn);
            // The catalog reports a coarse class-like for every .php file without
            // parsing it, so a functions.php surfaces as a phantom. Drop it before
            // the position filter, which is optimistic for names it cannot resolve.
            if (!$this->codeResolver->isClassLike($className)) {
                continue;
            }
            if (!$filter->accepts($className, $this->codeResolver)) {
                continue;
            }
            $items[] = CompletionItemFactory::forClass($symbol->shortName(), $fqcn, $replaceRange);
        }

        return $items;
    }
}
