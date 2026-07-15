<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Resolution\NameKind;
use Firehed\PhpLsp\Utility\NamespacePath;

/**
 * Discovers the symbols declared in the workspace, from the symbol index.
 *
 * Unlike the other sources this one is not cached: `vendor/` and the language's
 * built-ins are fixed for the life of the process, but the workspace changes
 * with every keystroke, and a stale answer here is a symbol that has just been
 * renamed still being offered.
 *
 * Class members are indexed too, but they are not symbols *of a namespace* — a
 * method is reached through its class, never by name — so they are skipped.
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
            $kind = self::nameKindOf($symbol->kind);
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
            $childNamespaces[strtolower($child)] = $child;
        }

        return new NamespaceContents(array_values($childNamespaces), $symbols);
    }

    /**
     * Discovery only distinguishes the kinds that name resolution distinguishes;
     * which flavour of class-like a symbol is takes resolving it.
     */
    private static function nameKindOf(SymbolKind $kind): ?NameKind
    {
        return match ($kind) {
            SymbolKind::Class_,
            SymbolKind::Interface_,
            SymbolKind::Trait_,
            SymbolKind::Enum_ => NameKind::ClassLike,
            SymbolKind::Function_ => NameKind::Function_,
            SymbolKind::Constant => NameKind::Constant,
            SymbolKind::Method, SymbolKind::Property => null,
        };
    }
}
