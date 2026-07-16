<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Protocol\Range;
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
    ) {
    }

    /**
     * The child namespaces of $namespace whose next segment matches $prefix.
     *
     * @return list<CompletionItem>
     */
    public function find(string $namespace, string $prefix, int $line, int $character): array
    {
        $contents = $this->catalog->childrenOf($namespace);
        // The partial segment the user is typing; a selection replaces just it.
        $replaceRange = Range::onLine($line, $character - strlen($prefix), $character);

        $items = [];
        foreach ($contents->childNamespaces as $child) {
            if (PrefixMatcher::matches(NamespacePath::shortNameOf($child), $prefix)) {
                $items[] = CompletionItemFactory::forNamespace($child);
            }
        }
        // The classes declared directly in the navigated namespace, discovered on
        // disk through the catalog. The reference is the leaf name — the earlier
        // segments are already typed — and the textEdit replaces the partial one.
        foreach ($contents->symbols as $symbol) {
            if (PrefixMatcher::matches($symbol->shortName(), $prefix)) {
                $items[] = CompletionItemFactory::forClass(
                    $symbol->shortName(),
                    $symbol->fullyQualifiedName,
                    $replaceRange,
                );
            }
        }

        return $items;
    }
}
