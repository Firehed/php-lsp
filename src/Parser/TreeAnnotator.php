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
 * The parent-connecting and name-resolving pass a tree takes once, however it
 * was read, so a downstream reader finds the parent links and resolved names
 * {@see ParsedDocument} promises.
 */
final class TreeAnnotator
{
    private readonly ParentConnectingVisitor $parents;
    private readonly NameResolver $names;

    /**
     * A name-resolution failure (a duplicate `use` alias, an unresolvable
     * relative name) is a compile error in the code, not a reason to lose its
     * tree, so it is swallowed and resolution continues.
     *
     * Swallowed errors are discarded, not collected: an annotator lives for
     * the session, and an error keeps its node, and through parent links a
     * whole document tree, alive.
     */
    public function __construct()
    {
        $this->parents = new ParentConnectingVisitor();
        $this->names = new NameResolver(new class implements ErrorHandler {
            public function handleError(Error $error): void
            {
            }
        });
    }

    /**
     * @param array<Node> $tree
     * @return array<Stmt>
     */
    public function annotate(array $tree): array
    {
        /** @var array<Stmt> */
        return (new NodeTraverser($this->parents, $this->names))->traverse($tree);
    }
}
