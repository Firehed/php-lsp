<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

/**
 * Enumerates what exists in a namespace.
 *
 * Completion needs to answer "what is inside `Psr\Log`?" — a question no
 * existing component can answer. Go-to-definition and hover only ever need
 * *lookup* (resolve one known name), which `ClassRepository` and reflection
 * already provide; completion needs *enumeration*, which nothing did.
 *
 * Implementations resolve one namespace at a time and are expected to be lazy:
 * the whole point is that navigating to `Psr\Log\` touches `Psr\Log` and
 * nothing else, so a large `vendor/` tree costs nothing until it is visited.
 */
interface NamespaceCatalog
{
    /**
     * The immediate children of a namespace. The global namespace is `''`.
     */
    public function childrenOf(string $namespace): NamespaceContents;
}
