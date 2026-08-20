<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Domain\NamespacePath;

/**
 * Discovers the symbols declared in the workspace, from the symbol index.
 *
 * Unlike the other sources this one is not cached: `vendor/` and the language's
 * built-ins are fixed for the life of the process, but the workspace changes
 * with every keystroke, and a stale answer here is a symbol that has just been
 * renamed still being offered.
 *
 * Class members are indexed too, but they are not symbols *of a namespace* — a
 * method is reached through its class, never by name — so they are skipped
 * ({@see SymbolKind::nameKind()} reports no name kind for them).
 */
final class WorkspaceNamespaceSource implements NamespaceCatalog
{
    public function __construct(
        private readonly SymbolIndex $index,
    ) {
    }

    public function childrenOf(string $namespace): NamespaceContents
    {
        $symbols = [];
        foreach ($this->index->inNamespace($namespace) as $symbol) {
            $kind = $symbol->kind->nameKind();
            if ($kind !== null) {
                $symbols[] = new CatalogSymbol($symbol->fullyQualifiedName, $kind);
            }
        }

        // A namespace deeper in the tree still tells us the namespace on the way
        // to it exists, even if nothing is declared there directly.
        $childNamespaces = [];
        foreach ($this->index->namespaces() as $symbolNamespace) {
            $below = NamespacePath::relativeTo($symbolNamespace, $namespace);
            if ($below === null) {
                continue;
            }

            $child = NamespacePath::join($namespace, NamespacePath::firstSegment($below));
            $childNamespaces[NamespacePath::normalize($child)] = $child;
        }

        return new NamespaceContents(array_values($childNamespaces), $symbols);
    }
}
