<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParsedDocument;
use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * The syntax read seam: a document in, the document and its tree out, and the
 * innermost node at a cursor offset over a tree the caller already holds.
 *
 * Consumers hold this interface and never an implementation, so a composite
 * over several sources drops in behind them without touching them (RFC 1 §4.11).
 * `nodeAt` takes the tree the caller obtained from `parse()` so no implementation
 * parses twice and the memo stays a pure decorator.
 */
interface SyntaxSourceInterface
{
    public function parse(TextDocument $document): ParsedDocument;

    /**
     * @param array<Stmt> $tree
     */
    public function nodeAt(array $tree, TextDocument $document, int $offset): ?Node;
}
