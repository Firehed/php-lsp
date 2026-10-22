<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\LateBindingKeyword;
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
     * Find the enclosing namespace node for a given node.
     *
     * Walks up the parent chain to find the namespace statement, if any.
     */
    public static function findEnclosingNamespace(Node $node): ?Stmt\Namespace_
    {
        $current = $node->getAttribute('parent');
        while ($current instanceof Node) {
            if ($current instanceof Stmt\Namespace_) {
                return $current;
            }
            $current = $current->getAttribute('parent');
        }
        return null;
    }

    /**
     * Resolve a class Name node in context, handling special names.
     *
     * Handles `self`, `static`, and `parent` by resolving them to the
     * appropriate class name based on the enclosing class context.
     *
     * @return ?class-string
     */
    public static function resolveClasslikeNameInContext(Name $name, Node $contextNode): ?string
    {
        $keyword = LateBindingKeyword::tryFromName($name->toString());
        if ($keyword !== null) {
            return $keyword->resolveIn(self::findEnclosingClassNode($contextNode));
        }

        /** @var class-string */
        return $name->toString();
    }

    /**
     * Get the fully qualified name of a class-like node.
     *
     * @return ?class-string
     */
    public static function getClassLikeName(Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_ $node): ?string
    {
        return LateBindingKeyword::Self->resolveIn($node);
    }

    /**
     * Find the fully qualified name of the enclosing class-like node.
     *
     * Returns the FQN if available, otherwise the short name, or null if not
     * in a class context.
     *
     * @return ?class-string
     */
    public static function findEnclosingClasslikeName(Node $node): ?string
    {
        return LateBindingKeyword::Self->resolveIn(self::findEnclosingClassNode($node));
    }
}
