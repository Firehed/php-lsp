<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

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
     * Resolve the class name of an expression.
     *
     * Handles:
     * - $this → enclosing class name
     * - Typed variables → delegated to TypeResolver
     *
     * @param array<Stmt> $ast
     * @return ?class-string
     */
    public static function resolveExpressionType(
        Expr $expr,
        array $ast,
        ?TypeResolverInterface $typeResolver,
    ): ?string {
        if ($expr instanceof Variable && $expr->name === 'this') {
            return ScopeFinder::findEnclosingClassName($expr);
        }

        if ($typeResolver === null) {
            return null;
        }

        $scope = ScopeFinder::findEnclosingScope($expr);
        if ($scope === null) {
            return null;
        }

        /** @var ?class-string */
        return $typeResolver->resolveExpressionType($expr, $scope, $ast);
    }
}
