<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

final class NodeAtPosition extends NodeVisitorAbstract
{
    private int $offset;
    private ?Node $found = null;

    /**
     * @param array<Node> $ast
     */
    public function find(array $ast, int $offset): ?Node
    {
        $this->offset = $offset;
        $this->found = null;

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this);
        $traverser->traverse($ast);

        return $this->found;
    }

    public function enterNode(Node $node): null
    {
        $startPos = $node->getStartFilePos();
        $endPos = $node->getEndFilePos();

        if ($startPos <= $this->offset && $this->offset <= $endPos) {
            // This node contains the position, keep drilling down
            // The last (most specific) match will be kept
            $this->found = $node;
        }

        return null;
    }
}
