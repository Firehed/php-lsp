<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

interface Type extends Formattable
{
    /**
     * @return list<ClassName>
     */
    public function getResolvableClassNames(): array;

    public function isNullable(): bool;

    /**
     * Resolve late-bound types (static/self/parent) to concrete types.
     *
     * In PHP, `self` in traits resolves to the class using the trait, not the
     * trait itself. This differs from `self` in regular classes, which resolves
     * to the declaring class. The `$declaringClassIsTrait` parameter allows
     * callers to distinguish these cases at resolution time.
     *
     * @param class-string $callingClass The class the method was called on
     * @param bool $declaringClassIsTrait Whether the method was declared in a trait
     */
    public function resolveLateBound(string $callingClass, bool $declaringClassIsTrait = false): Type;
}
