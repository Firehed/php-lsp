<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\TypeFactory;
use Firehed\PhpLsp\Resolution\TypeSource\TypeSourceInterface;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;

/**
 * Resolves the declared type of a parameter given its enclosing function-like
 * scope. One dispatch table for both {@see SymbolResolver::resolveParameter}
 * (hover on the declaration) and {@see ExpressionResolver::typeOfBinding} (a
 * variable that traces back to a parameter), so the same variable never gets
 * two different answers.
 *
 * Named scopes route through {@see TypeSourceInterface}. Closure and arrow parameters
 * have no source-blind identity; they fall through to {@see TypeFactory::fromNode}
 * as the explicit deferral to the future variable-typing seam (issue #517
 * "variable type-following. Not scope here").
 */
final class ParameterTyping
{
    public static function resolve(
        TypeSourceInterface $typeSource,
        Param $param,
        string $name,
        Stmt\Function_|Stmt\ClassMethod|Closure|ArrowFunction $enclosingScope,
        ?ClassName $enclosingClass,
        ?string $selfContext,
        ?string $parentContext,
    ): ?Type {
        if ($enclosingScope instanceof Stmt\ClassMethod && $enclosingClass !== null) {
            return $typeSource->forMethodParameter(
                $enclosingClass,
                new MethodName($enclosingScope->name->toString()),
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
        return TypeFactory::fromNode($param->type, $selfContext, $parentContext);
    }
}
