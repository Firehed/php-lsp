<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\TypeInference;

use Firehed\PhpLsp\Domain\Type;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Interface for resolving types of expressions and variables.
 *
 * Implementations may use simple heuristics (BasicTypeResolver) or
 * integrate with static analysis tools like PHPStan.
 */
interface TypeResolverInterface
{
    /**
     * Resolve the type of an expression.
     *
     * @param Expr $expr The expression to resolve
     * @param Stmt\Function_|Stmt\ClassMethod|Expr\Closure|Expr\ArrowFunction|null $scope
     *        The enclosing scope, if any
     * @param array<Stmt> $ast The full AST for context (class lookups, etc.)
     */
    public function resolveExpressionType(
        Expr $expr,
        Stmt\Function_|Stmt\ClassMethod|Expr\Closure|Expr\ArrowFunction|null $scope,
        array $ast,
    ): ?Type;

    /**
     * Resolve the type of a variable at a given position.
     *
     * @param string $variableName The variable name (without $)
     * @param Stmt\Function_|Stmt\ClassMethod|Expr\Closure|Expr\ArrowFunction $scope
     *        The enclosing scope
     * @param int $line The line number (0-based) where the variable is used
     * @param array<Stmt> $ast The full AST for context
     */
    public function resolveVariableType(
        string $variableName,
        Stmt\Function_|Stmt\ClassMethod|Expr\Closure|Expr\ArrowFunction $scope,
        int $line,
        array $ast,
    ): ?Type;
}
