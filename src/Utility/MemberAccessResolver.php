<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

use Firehed\PhpLsp\Domain\ClassName;
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
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/**
 * Resolves member access (method calls and property fetches) to domain objects.
 * Handles both regular (->) and nullsafe (?->) operators.
 */
final class MemberAccessResolver
{
    public function __construct(
        private readonly TypeResolverInterface $typeResolver,
        private readonly MemberResolver $memberResolver,
    ) {
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
            Visibility::Private,
        );
    }

    public function resolveStaticPropertyFetch(StaticPropertyFetch $fetch): ?PropertyInfo
    {
        $propertyName = $fetch->name;
        if (!$propertyName instanceof Identifier) {
            return null;
        }

        $class = $fetch->class;
        if (!$class instanceof Name) {
            return null;
        }

        $className = ScopeFinder::resolveClassNameInContext($class, $fetch);
        if ($className === null) {
            return null;
        }

        return $this->memberResolver->findProperty(
            new ClassName($className),
            new PropertyName($propertyName->toString()),
            Visibility::Private,
        );
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
