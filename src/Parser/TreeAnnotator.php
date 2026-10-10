<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser;

use PhpParser\Error;
use PhpParser\ErrorHandler;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;

/**
 * The parent-connecting and name-resolving pass every
 * {@see SyntaxSource\SyntaxSourceInterface} runs on its own result.
 *
 * A skeleton tree ({@see SyntaxSource\SkeletonSyntaxSource})
 * is annotated by the same code as a parsed one, so a downstream
 * reader finds the parent links and resolved names
 * {@see ParsedDocument} promises regardless of which
 * source produced the tree.
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
     *
     *        Swallowed errors are discarded, not collected: an annotator lives
     *        for the session, and an error keeps its node, and through parent
     *        links a whole document tree, alive.
     */
    public function __construct(bool $tolerant = false)
    {
        $this->traverser = new NodeTraverser();
        $this->traverser->addVisitor(new ParentConnectingVisitor());
        $this->traverser->addVisitor($tolerant ? new NameResolver(new class implements ErrorHandler {
            public function handleError(Error $error): void
            {
            }
        }) : new NameResolver());
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
