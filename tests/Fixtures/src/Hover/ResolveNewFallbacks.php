<?php

declare(strict_types=1);

namespace Fixtures\Hover;

/**
 * Cases the resolver reaches by walking the receiver of a method call.
 * Each triggers a fallback branch in ExpressionResolver::resolveNew:
 * a class expression that is not a Name, an unresolvable late-binding
 * keyword, and a name that resolves but is not located.
 */
final class ResolveNewFallbacks
{
    public function newWithVariableClass(): void
    {
        $factory = self::class;
        (new $factory())->foo(); //hover:variable_new
    }

    public function newWithAnonymousClass(): void
    {
        (new class {
            public function foo(): void
            {
            }
        })->foo(); //hover:anon_new
    }

    public function newWithUnknownClass(): void
    {
        (new NoSuchClassInWorkspace())->foo(); //hover:unknown_new
    }
}

// `self` at global scope has no enclosing class-like, so
// resolveClassNameInContext returns null.
function newSelfAtGlobalScope(): void
{
    (new self())->foo(); //hover:global_self_new
}
