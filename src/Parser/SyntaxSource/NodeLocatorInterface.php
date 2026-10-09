<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Parser\ParsedDocument;
use PhpParser\Node;

/**
 * The cursor read seam: the innermost node below statement level at an offset
 * within a parsed document (a name, an identifier, an expression, an
 * argument), or null where only statements or nothing enclose the offset.
 *
 * A returned node carries the guarantees {@see ParsedDocument} states, and its
 * parent links lead into the parsed document's tree.
 */
interface NodeLocatorInterface
{
    public function nodeAt(ParsedDocument $parsed, int $offset): ?Node;
}
