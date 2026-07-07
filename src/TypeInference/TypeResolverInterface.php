<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\TypeInference;

use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Utility\Scope;
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
     * @param Scope $scope The enclosing lexical scope
     * @param array<Stmt> $ast The full AST for context (class lookups, etc.)
     */
    public function resolveExpressionType(
        Expr $expr,
        Scope $scope,
        array $ast,
    ): ?Type;

    /**
     * Resolve the type of a variable at a given position.
     *
     * @param string $variableName The variable name (without $)
     * @param Scope $scope The enclosing lexical scope
     * @param int $line The line number (0-based) where the variable is used
     * @param array<Stmt> $ast The full AST for context
     */
    public function resolveVariableType(
        string $variableName,
        Scope $scope,
        int $line,
        array $ast,
    ): ?Type;
}
