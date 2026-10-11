<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\NodeLocator;

use Firehed\PhpLsp\Parser\NodeAtPosition;
use Firehed\PhpLsp\Parser\ParsedDocument;
use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * The {@see NodeLocatorInterface} that reads the parsed document's tree.
 * A statement (a class, a method, the namespace) is the innermost node only
 * where the tree holds nothing the cursor names, so it is no answer.
 */
final class TreeNodeLocator implements NodeLocatorInterface
{
    public function nodeAt(ParsedDocument $parsed, int $offset): ?Node
    {
        $node = (new NodeAtPosition())->find($parsed->tree, $offset);
        if ($node instanceof Stmt) {
            return null;
        }
        return $node;
    }
}
