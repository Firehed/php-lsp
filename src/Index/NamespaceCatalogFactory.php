<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

/**
 * Composes the {@see NamespaceCatalog} used for completion discovery from its
 * three sources, applying the one caching rule that matters: the workspace
 * changes with every keystroke and must never be cached, while `vendor/` and the
 * built-ins are fixed for the life of the process and are cached together.
 */
final class NamespaceCatalogFactory
{
    public static function forProject(SymbolIndex $index, string $projectRoot): NamespaceCatalog
    {
        return new CompositeNamespaceCatalog([
            new WorkspaceNamespaceSource($index),
            new CachedNamespaceCatalog(new CompositeNamespaceCatalog([
                new ComposerNamespaceSource(ComposerAutoloadMap::fromProjectRoot($projectRoot)),
                new ReflectionNamespaceSource(),
            ])),
        ]);
    }
}
