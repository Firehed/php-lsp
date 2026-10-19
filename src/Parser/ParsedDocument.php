<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser;

use Firehed\PhpLsp\Document\TextDocument;
use LogicException;
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
 * - On every call and attribute, an {@see self::ARGUMENT_LIST} attribute: its
 *   {@see ArgumentList}, or null when it is written without parentheses
 *   (`new Foo`, `#[Foo]`). An anonymous class written with empty parentheses
 *   (`new class() {}`), and an unclosed `new Foo(` the parser recovered, also
 *   record null: the tree does not place their `(`.
 */
final readonly class ParsedDocument
{
    public const string ARGUMENT_LIST = 'argumentList';

    /**
     * @param array<Stmt> $tree
     */
    public function __construct(
        public TextDocument $document,
        public array $tree,
    ) {
    }

    /**
     * The {@see self::ARGUMENT_LIST} a call carries.
     *
     * @throws LogicException when the source that built the call did not record it
     */
    public static function argumentListOf(Node $call): ?ArgumentList
    {
        $recorded = $call->getAttribute(self::ARGUMENT_LIST, false);
        if ($recorded !== null && !$recorded instanceof ArgumentList) {
            throw new LogicException("A syntax source returned a {$call->getType()} without its argument list");
        }
        return $recorded;
    }
}
