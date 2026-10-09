<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Parser\ParsedDocument;
use PhpParser\Node;

/**
 * The cursor read seam: the innermost node at an offset within a parsed
 * document, or null when nothing there names a symbol.
 *
 * A returned node carries the guarantees {@see ParsedDocument} states, and its
 * parent links lead into the parsed document's tree.
 */
interface NodeLocatorInterface
{
    public function nodeAt(ParsedDocument $parsed, int $offset): ?Node;
}
