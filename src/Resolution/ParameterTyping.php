<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\TypeFactory;
use Firehed\PhpLsp\Domain\TypeInterface;
use Firehed\PhpLsp\Resolution\TypeSource\TypeSourceInterface;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;

/**
 * Resolves the declared type of a parameter given its enclosing function-like
 * scope. One dispatch table for both {@see ExpressionResolver::resolveParameter}
 * (hover on the declaration) and {@see ExpressionResolver::typeOfBinding} (a
 * variable that traces back to a parameter), so the same variable never gets
 * two different answers.
 *
 * Named scopes route through {@see TypeSourceInterface}. Closure, arrow, and hook parameters
 * have no source-blind identity; they fall through to {@see TypeFactory::fromNode}
 * as the explicit deferral to the future variable-typing seam (issue #517
 * "variable type-following. Not scope here"), with `self` and `parent` bound
 * by the scope.
 */
final class ParameterTyping
{
    public static function resolve(
        TypeSourceInterface $typeSource,
        Param $param,
        string $name,
        Scope $scope,
    ): ?TypeInterface {
        $enclosingScope = $scope->getSourceNode();
        assert($enclosingScope !== null, 'a parameter is declared by a function-like');
        $self = $scope->getSelfName();
        if ($enclosingScope instanceof Stmt\ClassMethod && $self !== null) {
            return $typeSource->forMethodParameter(
                new MethodName($self, $enclosingScope->name->toString()),
                $name,
            );
        }
        if ($enclosingScope instanceof Stmt\Function_) {
            $fqn = $enclosingScope->namespacedName?->toString() ?? $enclosingScope->name->toString();
            return $typeSource->forFunctionParameter(
                FunctionName::fromFullyQualified($fqn),
                $name,
            );
        }
        $type = TypeFactory::fromNode($param->type, preserveLateBinding: true);
        $binding = $scope->lateBinding();
        return $binding === null ? $type : $type?->resolveLateBound($binding);
    }
}
