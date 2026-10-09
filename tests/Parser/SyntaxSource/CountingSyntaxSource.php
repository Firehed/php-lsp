<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;
use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * Test-only SyntaxSourceInterface stub that records how many times it was asked to
 * parse, so the memoizer's dedup can be pinned on the count.
 */
final class CountingSyntaxSource implements SyntaxSourceInterface
{
    public int $parseCount = 0;

    /**
     * @param array<Stmt> $tree
     */
    public function __construct(private readonly array $tree)
    {
    }

    public function parse(TextDocument $document): ParsedDocument
    {
        $this->parseCount++;
        return new ParsedDocument($document, $this->tree);
    }

    /**
     * @param array<Stmt> $tree
     */
    public function nodeAt(array $tree, TextDocument $document, int $offset): ?Node
    {
        return null;
    }
}
