<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

/**
 * Presents several discovery sources as one catalog.
 *
 * Sources overlap by design — a class in the workspace is also a file under a
 * PSR-4 prefix, and both will report it — so results are deduplicated by name.
 *
 * This does no caching itself. Whether an answer may be reused depends on the
 * source: `vendor/` and the built-ins are fixed for the life of the process,
 * while the workspace changes with every keystroke. Wrap the stable sources in
 * {@see CachedNamespaceCatalog} instead of caching here, where the volatile
 * source would be cached too.
 */
final class CompositeNamespaceCatalog implements NamespaceCatalog
{
    /**
     * @param list<NamespaceCatalog> $sources
     */
    public function __construct(
        private readonly array $sources,
    ) {
    }

    public function childrenOf(string $namespace): NamespaceContents
    {
        return NamespaceContents::merge(array_map(
            static fn(NamespaceCatalog $source): NamespaceContents => $source->childrenOf($namespace),
            $this->sources,
        ));
    }
}
