<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt;

/**
 * Utility for finding enclosing scopes in an AST.
 */
final class ScopeFinder
{
    /**
     * Find the enclosing function/method/closure for a node.
     *
     * Walks up the parent chain to find the innermost scope.
     */
    public static function findEnclosingScope(
        Node $node,
    ): Stmt\Function_|Stmt\ClassMethod|Closure|ArrowFunction|null {
        $current = $node->getAttribute('parent');
        while ($current instanceof Node) {
            if (
                $current instanceof Stmt\Function_
                || $current instanceof Stmt\ClassMethod
                || $current instanceof Closure
                || $current instanceof ArrowFunction
            ) {
                return $current;
            }
            $current = $current->getAttribute('parent');
        }
        return null;
    }
}
