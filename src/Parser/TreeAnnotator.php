<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser;

use PhpParser\ErrorHandler\Collecting;
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

    /**
     * @param bool $tolerant When true, a name-resolution failure (a duplicate
     *        `use` alias, an unresolvable relative name) is swallowed rather
     *        than thrown. The skeleton {@see SyntaxSource\SkeletonSyntaxSource}
     *        builds trees from broken files where either can appear, and the
     *        `phpstan.neon` traversal allowlist confines the two-visitor stack
     *        to this class — a separate tolerant annotator cannot live outside
     *        the allowlist, so the mode lives here instead. The php-parser
     *        source runs in the strict default so a truly unrepresentable AST
     *        still yields no statements.
     */
    public function __construct(bool $tolerant = false)
    {
        $this->traverser = new NodeTraverser();
        $this->traverser->addVisitor(new ParentConnectingVisitor());
        $this->traverser->addVisitor($tolerant ? new NameResolver(new Collecting()) : new NameResolver());
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
