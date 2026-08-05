<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

/**
 * The union of what several catalogs report about a namespace.
 *
 * One backend can reach a namespace by more than one route: Composer's autoload maps
 * turn a namespace into a directory listing, while the `autoload.files` set is
 * addressed by no name at all and is enumerated from a derived index instead. Both
 * describe the same namespace, so enumeration is their merge — the same shape
 * {@see \Firehed\PhpLsp\Knowledge\CompositeSymbolLocator} gives the lookup half.
 *
 * Catalogs are passed in order of authority, so the earlier one settles a name they
 * both report ({@see NamespaceContents::merge()}).
 */
final class CompositeNamespaceCatalog implements NamespaceCatalog
{
    /**
     * @param list<NamespaceCatalog> $catalogs In precedence order
     */
    public function __construct(
        private readonly array $catalogs,
    ) {
    }

    public function childrenOf(string $namespace): NamespaceContents
    {
        return NamespaceContents::merge(array_map(
            static fn(NamespaceCatalog $catalog): NamespaceContents => $catalog->childrenOf($namespace),
            $this->catalogs,
        ));
    }
}
