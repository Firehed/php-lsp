<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;

/**
 * The one-route composite for {@see SyntaxSource}. Members are asked in order,
 * and the first non-empty result wins; the empty list is returned only when
 * every member returned it, so a fallback (a skeleton tree, a cursor-local
 * fragment) reaches consumers exactly when the earlier members had nothing to
 * say (RFC 1 §4.11).
 */
final class CompositeSyntaxSource implements SyntaxSource
{
    /**
     * @param list<SyntaxSource> $sources
     */
    public function __construct(
        private readonly array $sources,
    ) {
    }

    /**
     * @return array<\PhpParser\Node\Stmt>
     */
    public function parse(TextDocument $document): array
    {
        foreach ($this->sources as $source) {
            $tree = $source->parse($document);
            if ($tree !== []) {
                return $tree;
            }
        }
        return [];
    }
}
