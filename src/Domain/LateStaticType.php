<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/** Represents a late-static-binding type (static, self, or parent). */
final class LateStaticType implements Type
{
    public function __construct(
        public readonly LateBindingKeyword $keyword,
        public readonly ClassName $declaringClass,
    ) {
    }

    public function format(): string
    {
        return $this->keyword->value;
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
     * @param class-string $callingClass The class from which the method was called
     * @param class-string|null $callingParent The parent of the calling class (for parent::)
     */
    public function resolve(string $callingClass, ?string $callingParent = null): ClassName
    {
        return match ($this->keyword) {
            LateBindingKeyword::Self, LateBindingKeyword::Static => new ClassName($callingClass),
            LateBindingKeyword::Parent => new ClassName($callingParent ?? $this->declaringClass->fqn),
        };
    }
}
