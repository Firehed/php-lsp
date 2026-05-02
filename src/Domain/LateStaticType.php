<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/** Represents a late-static-binding type (static, self, or parent). */
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
     * @param class-string $callingClass The class from which the method was called
     * @param class-string|null $callingParent The parent of the calling class (for parent::)
     */
    public function resolve(string $callingClass, ?string $callingParent = null): ClassName
    {
        // Both `self` and `static` resolve to callingClass. This is correct for traits (where
        // self binds to the using class) and pragmatic for inheritance (avoids tracking origin).
        return match ($this->keyword) {
            'self', 'static' => new ClassName($callingClass),
            'parent' => new ClassName($callingParent ?? $this->declaringClass->fqn),
            // @codeCoverageIgnoreStart
            default => throw new \LogicException("Invalid late-binding keyword: {$this->keyword}"),
            // @codeCoverageIgnoreEnd
        };
    }
}
