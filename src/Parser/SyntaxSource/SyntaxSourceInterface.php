<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParsedDocument;
use PhpParser\Node;

/**
 * The syntax read seam: a document in, the document and its tree out, and the
 * innermost node at a cursor offset within a parsed document.
 *
 * Consumers hold this interface and never an implementation, so a composite
 * over several sources drops in behind them without touching them (RFC 1 §4.11).
 * `nodeAt` takes what the caller obtained from `parse()` so no implementation
 * parses twice and the memo stays a pure decorator.
 */
interface SyntaxSourceInterface
{
    public function parse(TextDocument $document): ParsedDocument;

    public function nodeAt(ParsedDocument $parsed, int $offset): ?Node;
}
