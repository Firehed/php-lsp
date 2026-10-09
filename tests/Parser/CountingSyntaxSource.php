<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;

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

    public function parse(TextDocument $document): ParsedDocument
    {
        $this->parseCount++;
        return $this->inner->parse($document);
    }
}
