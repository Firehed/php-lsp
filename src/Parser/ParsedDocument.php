<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser;

use Firehed\PhpLsp\Document\TextDocument;
use PhpParser\Node\Stmt;

/**
 * A document and the tree parsed from it.
 *
 * Every node in the tree, and every node found at a position within it,
 * carries the same guarantees whichever source produced it:
 *
 * - `startFilePos`, `endFilePos`, and `startLine` position attributes.
 * - A `parent` attribute on every node but a top-level statement.
 * - Names rewritten in place to their fully qualified form, except `self`,
 *   `parent`, and `static`, and an unqualified function or constant name in
 *   a namespace, which PHP resolves at runtime: it keeps its written form and
 *   carries its `namespacedName`.
 */
final readonly class ParsedDocument
{
    /**
     * @param array<Stmt> $tree
     */
    public function __construct(
        public TextDocument $document,
        public array $tree,
    ) {
    }
}
