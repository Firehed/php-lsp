<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * The syntax read seam: a document in, its top-level statements out, and the
 * innermost node at a cursor offset over a tree the caller already holds.
 *
 * Consumers hold this interface and never an implementation, so a composite
 * over several sources drops in behind them without touching them (RFC 1 §4.11).
 * `nodeAt` takes the tree the caller obtained from `parse()` so no implementation
 * parses twice and the memo stays a pure decorator.
 *
 * Every node `parse()` returns, and every node `nodeAt()` returns, carries the
 * same guarantees whichever implementation produced it:
 *
 * - `startFilePos`, `endFilePos`, and `startLine` position attributes.
 * - A `parent` attribute on every node but a top-level statement.
 * - Names rewritten in place to their fully qualified form, except `self`,
 *   `parent`, and `static`, and an unqualified function or constant name in
 *   a namespace, which PHP resolves at runtime: it keeps its written form and
 *   carries its `namespacedName`.
 * - On every call and attribute, an {@see self::ARGUMENT_SEPARATORS} attribute:
 *   the file positions, in order, of the commas between its own arguments,
 *   a trailing comma included.
 */
interface SyntaxSourceInterface
{
    public const string ARGUMENT_SEPARATORS = 'argumentSeparators';

    /**
     * @return array<Stmt>
     */
    public function parse(TextDocument $document): array;

    /**
     * @param array<Stmt> $tree
     */
    public function nodeAt(array $tree, TextDocument $document, int $offset): ?Node;
}
