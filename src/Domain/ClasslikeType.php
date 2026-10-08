<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

final readonly class ClasslikeType implements TypeInterface
{
    public function __construct(
        public ClasslikeName $name,
    ) {
    }

    public function format(): string
    {
        return $this->name->qualifiedName->fullyQualifiedName();
    }

    /**
     * @return list<ClasslikeName>
     */
    public function getResolvableClasslikeNames(): array
    {
        return [$this->name];
    }

    public function isNullable(): bool
    {
        return false;
    }

    public function equals(TypeInterface $other): bool
    {
        return $other instanceof self
            && $this->name->equals($other->name);
    }

    public function resolveLateBound(string $callingClass, bool $declaringClassIsTrait = false): TypeInterface
    {
        return $this;
    }
}
