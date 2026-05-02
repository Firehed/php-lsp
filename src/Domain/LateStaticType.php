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

    public function resolveLateBound(string $callingClass): Type
    {
        return match ($this->keyword) {
            LateBindingKeyword::Self, LateBindingKeyword::Static => new ClassName($callingClass),
            LateBindingKeyword::Parent => new ClassName($this->declaringClass->fqn),
        };
    }
}
