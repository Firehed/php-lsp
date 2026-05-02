<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Represents a late-static-binding type (static, self, or parent).
 *
 * These types are not resolved at parse time because they depend on the
 * calling context. For example, a trait method returning `static` should
 * resolve to the class using the trait, not the trait itself.
 */
final class LateStaticType implements Type
{
    public function __construct(
        public readonly string $keyword,
        public readonly ClassName $declaringClass,
    ) {
    }

    public function format(): string
    {
        return $this->keyword;
    }

    /**
     * @return list<ClassName>
     */
    public function getResolvableClassNames(): array
    {
        return [$this->declaringClass];
    }

    public function isNullable(): bool
    {
        return false;
    }

    /**
     * Resolve this late-binding type to an actual class name.
     *
     * @param class-string $callingClass The class from which the method was called
     * @param class-string|null $callingParent The parent of the calling class (for parent::)
     */
    public function resolve(string $callingClass, ?string $callingParent = null): ClassName
    {
        return match ($this->keyword) {
            'parent' => new ClassName($callingParent ?? $this->declaringClass->fqn),
            default => new ClassName($callingClass),
        };
    }
}
