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
    /** @var ?\Closure(Node): bool */
    private ?\Closure $filter = null;

    /**
     * @param array<Node> $ast
     * @param ?\Closure(Node): bool $filter
     */
    public function find(array $ast, int $offset, ?\Closure $filter = null): ?Node
    {
        $this->offset = $offset;
        $this->found = null;
        $this->filter = $filter;

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
            if ($this->filter === null || ($this->filter)($node)) {
                $this->found = $node;
            }
        }

        return null;
    }
}
