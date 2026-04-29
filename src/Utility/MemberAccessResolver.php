<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\TypeInference\TypeResolverInterface;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Stmt;

/**
 * Resolves member access (method calls and property fetches) to domain objects.
 * Handles both regular (->) and nullsafe (?->) operators.
 */
final class MemberAccessResolver
{
    public function __construct(
        private readonly TypeResolverInterface $typeResolver,
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

    public static function isMethodCall(mixed $node): bool
    {
        return $node instanceof MethodCall || $node instanceof NullsafeMethodCall;
    }

    public static function isPropertyFetch(mixed $node): bool
    {
        return $node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch;
    }
}
