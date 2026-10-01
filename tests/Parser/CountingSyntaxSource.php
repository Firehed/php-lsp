<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;
use PhpParser\Node;

/**
 * Test-only decorator that counts {@see SyntaxSourceInterface::parse} calls on
 * its inner source. Lets a test assert that memoization or caching held without
 * threading an observer through production constructors.
 */
final class CountingSyntaxSource implements SyntaxSourceInterface
{
    public int $parseCount = 0;

    public function __construct(private readonly SyntaxSourceInterface $inner)
    {
    }

    public function parse(TextDocument $document): array
    {
        $this->parseCount++;
        return $this->inner->parse($document);
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $tree
     */
    public function nodeAt(array $tree, TextDocument $document, int $offset): ?Node
    {
        return $this->inner->nodeAt($tree, $document, $offset);
    }
}
