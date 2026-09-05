<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;

/**
 * The parent-connecting and name-resolving pass every tree-producing
 * {@see SyntaxSource\SyntaxSource} runs on its own result.
 *
 * A skeleton tree ({@see SyntaxSource\SkeletonSyntaxSource}, build-manifest
 * step-37) is annotated by the same code as a parsed one, so a downstream
 * reader that expects `parent`, `resolvedName`, or `namespacedName` finds
 * them regardless of which source produced the tree.
 */
final class TreeAnnotator
{
    private NodeTraverser $traverser;

    public function __construct()
    {
        $this->traverser = new NodeTraverser();
        $this->traverser->addVisitor(new ParentConnectingVisitor());
        $this->traverser->addVisitor(new NameResolver());
    }

    /**
     * @param array<Node> $tree
     * @return array<Stmt>
     */
    public function annotate(array $tree): array
    {
        /** @var array<Stmt> */
        return $this->traverser->traverse($tree);
    }
}
