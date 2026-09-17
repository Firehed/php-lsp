<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * The type-graph node for a class-like value. Pairs an identifying
 * {@see ClasslikeName} with the type arguments that appear at the use site,
 * so a `Collection<User>` reads as one type expression while the underlying
 * `Collection` name stays a pure identifier for lookup.
 */
final readonly class ClasslikeType implements TypeInterface
{
    /**
     * @param list<TypeInterface> $typeArguments
     */
    public function __construct(
        public ClasslikeName $name,
        private array $typeArguments = [],
    ) {
    }

    public function format(): string
    {
        return $this->name->fqn;
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
        if (!$other instanceof self) {
            return false;
        }
        if (!$this->name->equals($other->name)) {
            return false;
        }
        if (count($this->typeArguments) !== count($other->typeArguments)) {
            return false;
        }
        foreach ($this->typeArguments as $i => $arg) {
            if (!$arg->equals($other->typeArguments[$i])) {
                return false;
            }
        }
        return true;
    }

    public function resolveLateBound(string $callingClass, bool $declaringClassIsTrait = false): TypeInterface
    {
        return $this;
    }

    public function valueType(): ?TypeInterface
    {
        return $this->typeArguments[0] ?? null;
    }
}
