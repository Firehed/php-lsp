<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Name;
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

    /**
     * Find the enclosing class-like node (class, interface, trait, or enum).
     *
     * Walks up the parent chain to find the innermost class-like scope.
     */
    public static function findEnclosingClassNode(
        Node $node,
    ): Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null {
        $current = $node->getAttribute('parent');
        while ($current instanceof Node) {
            if (
                $current instanceof Stmt\Class_
                || $current instanceof Stmt\Interface_
                || $current instanceof Stmt\Trait_
                || $current instanceof Stmt\Enum_
            ) {
                return $current;
            }
            $current = $current->getAttribute('parent');
        }
        return null;
    }

    /**
     * Resolve a Name node to its fully qualified name.
     *
     * Uses the resolved name attribute if available (from NameResolver),
     * otherwise falls back to the raw name.
     */
    public static function resolveName(Name $name): string
    {
        $resolvedName = $name->getAttribute('resolvedName');
        return $resolvedName instanceof Name
            ? $resolvedName->toString()
            : $name->toString();
    }

    /**
     * Find the fully qualified name of the enclosing class-like node.
     *
     * Returns the FQN if available, otherwise the short name, or null if not
     * in a class context.
     */
    public static function findEnclosingClassName(Node $node): ?string
    {
        $classNode = self::findEnclosingClassNode($node);
        if ($classNode === null || $classNode->name === null) {
            return null;
        }

        $namespacedName = $classNode->namespacedName;
        if ($namespacedName instanceof Name) {
            return $namespacedName->toString();
        }
        return $classNode->name->toString();
    }
}
