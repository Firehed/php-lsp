<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSource;
use PhpParser\Node\Stmt;

/**
 * Test-only SyntaxSource stub that records how many times it was asked to
 * parse, so the memoizer's dedup can be pinned on the count.
 */
final class CountingSyntaxSource implements SyntaxSource
{
    public int $parseCount = 0;

    /**
     * @param array<Stmt> $tree
     */
    public function __construct(private readonly array $tree)
    {
    }

    /**
     * @return array<Stmt>
     */
    public function parse(TextDocument $document): array
    {
        $this->parseCount++;
        return $this->tree;
    }
}
