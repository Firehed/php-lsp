<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\TypeInference\TypeResolverInterface;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;

/**
 * Resolves member access (method calls and property fetches) to domain objects.
 * Handles both regular (->) and nullsafe (?->) operators.
 */
final class MemberAccessResolver
{
    public function __construct(
        private readonly MemberResolver $memberResolver,
        private readonly TypeResolverInterface $typeResolver,
    ) {
    }

    /**
     * @param array<Stmt> $ast
     */
    public function resolveMethodCall(MethodCall|NullsafeMethodCall $call, array $ast): ?MethodInfo
    {
        $methodName = $call->name;
        if (!$methodName instanceof Identifier) {
            return null;
        }

        $className = $this->resolveObjectClassName($call->var, $ast);
        if ($className === null) {
            return null;
        }

        return $this->memberResolver->findMethod(
            $className,
            new MethodName($methodName->toString()),
            Visibility::Public,
        );
    }

    /**
     * @param array<Stmt> $ast
     */
    public function resolvePropertyFetch(PropertyFetch|NullsafePropertyFetch $fetch, array $ast): ?PropertyInfo
    {
        $propertyName = $fetch->name;
        if (!$propertyName instanceof Identifier) {
            return null;
        }

        $className = $this->resolveObjectClassName($fetch->var, $ast);
        if ($className === null) {
            return null;
        }

        return $this->memberResolver->findProperty(
            $className,
            new PropertyName($propertyName->toString()),
            Visibility::Public,
        );
    }

    /**
     * @param array<Stmt> $ast
     */
    public function resolveObjectClassName(Expr $var, array $ast): ?ClassName
    {
        $type = ExpressionTypeResolver::resolveExpressionType($var, $ast, $this->typeResolver);
        $classNames = $type?->getResolvableClassNames() ?? [];

        return $classNames[0] ?? null;
    }

    public static function isMethodCall(mixed $node): bool
    {
        return $node instanceof MethodCall || $node instanceof NullsafeMethodCall;
    }

    public static function isPropertyFetch(mixed $node): bool
    {
        return $node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch;
    }
}
