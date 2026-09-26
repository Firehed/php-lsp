<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use PhpParser\Node;

/**
 * The one-route composite for {@see SyntaxSourceInterface}. Members are asked
 * in order — primary parse, skeleton fallback, cursor-local fragment — and the
 * first non-empty result wins; the empty list is returned only when every
 * member returned it, so a fallback reaches consumers exactly when the earlier
 * members had nothing to say (RFC 1 §4.11).
 */
final class CompositeSyntaxSource implements SyntaxSourceInterface
{
    /** @var list<SyntaxSourceInterface> */
    private readonly array $sources;

    public function __construct(
        PhpParserSyntaxSource $primary,
        SkeletonSyntaxSource $skeleton,
        CursorTextSyntaxSource $cursor,
    ) {
        $this->sources = [$primary, $skeleton, $cursor];
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

    /**
     * @param array<\PhpParser\Node\Stmt> $tree
     */
    public function nodeAt(array $tree, TextDocument $document, int $offset): ?Node
    {
        foreach ($this->sources as $source) {
            $node = $source->nodeAt($tree, $document, $offset);
            if ($node !== null) {
                return $node;
            }
        }
        return null;
    }
}
