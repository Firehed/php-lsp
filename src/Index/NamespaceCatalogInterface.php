<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Domain\NameKind;

/**
 * Enumerates what exists in a namespace.
 *
 * Completion needs to answer "what is inside `Psr\Log`?" — a question no
 * existing component can answer. Go-to-definition and hover only ever need
 * *lookup* (resolve one known name), which the `SymbolSourceInterface` backends already
 * provide; completion needs *enumeration*, which nothing did.
 *
 * Implementations resolve one namespace at a time and are expected to be lazy:
 * the whole point is that navigating to `Psr\Log\` touches `Psr\Log` and
 * nothing else, so a large `vendor/` tree costs nothing until it is visited.
 */
interface NamespaceCatalogInterface
{
    /**
     * The immediate children of a namespace. The global namespace is `''`.
     */
    public function childrenOf(string $namespace): NamespaceContents;

    /**
     * Symbols of a kind whose short name begins with a prefix. A catalog whose
     * index is unbounded (a workspace directory tree) returns `[]`; a bounded,
     * already-indexed one answers from that index.
     *
     * @return list<Symbol>
     */
    public function searchByPrefix(string $prefix, NameKind $kind): array;
}
