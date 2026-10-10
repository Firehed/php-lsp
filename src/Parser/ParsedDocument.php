<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser;

use Firehed\PhpLsp\Document\TextDocument;
use PhpParser\Node;
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
 *   carries its `namespacedName`. A namespace or import declaration's own
 *   name is what it declares, so it too keeps its written form.
 * - On every call and attribute, an {@see self::ARGUMENT_SEPARATORS} attribute:
 *   the file positions, in order, of the commas between its own arguments,
 *   a trailing comma included.
 */
final readonly class ParsedDocument
{
    public const string ARGUMENT_SEPARATORS = 'argumentSeparators';

    /**
     * @param array<Stmt> $tree
     */
    public function __construct(
        public TextDocument $document,
        public array $tree,
    ) {
    }

    /**
     * The {@see self::ARGUMENT_SEPARATORS} a call carries, or null when it
     * carries none or they are not file positions.
     *
     * @return ?list<int>
     */
    public static function argumentSeparatorsOf(Node $call): ?array
    {
        $recorded = $call->getAttribute(self::ARGUMENT_SEPARATORS);
        if (!is_array($recorded) || !array_is_list($recorded)) {
            return null;
        }
        $positions = [];
        foreach ($recorded as $position) {
            if (!is_int($position)) {
                return null;
            }
            $positions[] = $position;
        }
        return $positions;
    }
}
