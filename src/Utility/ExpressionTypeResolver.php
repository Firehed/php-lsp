<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\TypeInference\TypeResolverInterface;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt;

/**
 * Utility for resolving the type of an expression.
 *
 * Consolidates the logic for determining what class an expression refers to,
 * handling $this and delegating to TypeResolver for typed variables.
 */
final class ExpressionTypeResolver
{
    /**
     * Resolve the type of an expression.
     *
     * Handles:
     * - $this → enclosing class name
     * - Typed variables → delegated to TypeResolver
     *
     * @param array<Stmt> $ast
     */
    public static function resolveExpressionType(
        Expr $expr,
        array $ast,
        ?TypeResolverInterface $typeResolver,
    ): ?Type {
        if ($expr instanceof Variable && $expr->name === 'this') {
            $className = ScopeFinder::findEnclosingClassName($expr);
            if ($className === null) {
                return null;
            }
            return new ClassName($className);
        }

        if ($typeResolver === null) {
            return null;
        }

        $scope = ScopeFinder::findEnclosingScope($expr);
        if ($scope === null) {
            return null;
        }

        return $typeResolver->resolveExpressionType($expr, $scope, $ast);
    }
}
