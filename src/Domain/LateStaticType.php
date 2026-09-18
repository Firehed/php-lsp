<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/** Represents a late-static-binding type (static, self, or parent). */
final class LateStaticType implements TypeInterface
{
    public function __construct(
        public readonly LateBindingKeyword $keyword,
        public readonly ClasslikeName $declaringClass,
    ) {
    }

    public function format(): string
    {
        return $this->keyword->value;
    }

    /**
     * @return list<ClasslikeName>
     */
    public function getResolvableClasslikeNames(): array
    {
        return [$this->declaringClass];
    }

    public function isNullable(): bool
    {
        return false;
    }

    public function resolveLateBound(string $callingClass, bool $declaringClassIsTrait = false): TypeInterface
    {
        return match ($this->keyword) {
            LateBindingKeyword::Self => $declaringClassIsTrait
                ? new ClasslikeType(ClasslikeName::fromFullyQualified($callingClass))
                : new ClasslikeType($this->declaringClass),
            LateBindingKeyword::Static => new ClasslikeType(ClasslikeName::fromFullyQualified($callingClass)),
            LateBindingKeyword::Parent => new ClasslikeType($this->declaringClass),
        };
    }

    public function valueType(): ?TypeInterface
    {
        return null;
    }

    public function equals(TypeInterface $other): bool
    {
        return $other instanceof self
            && $this->keyword === $other->keyword
            && $this->declaringClass->equals($other->declaringClass);
    }
}
