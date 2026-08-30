<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\DeclaredSymbol;

/**
 * The third {@see DeclaredSymbol} producer contract, alongside the AST-based scanner
 * and the reflection-based factory (RFC 1 §5.3): given raw text from a document
 * whose parse yielded no declarations, extract class-like symbols so `MemberResolver`
 * still answers — one consumer, three producers, no parallel member walker.
 *
 * The implementation lives in the Resolution layer where the regex primitives are
 * confined (`DefaultTextSymbolExtractor`); this interface keeps the sink's dependency
 * satisfiable within the Knowledge layer.
 */
interface TextSymbolExtractor
{
    /**
     * @return list<DeclaredSymbol>
     */
    public function extract(string $content, string $filePath): array;
}
