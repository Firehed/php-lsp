<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * `static`, `self`, or `parent` as a declaration writes it, before the class it
 * names is known; see {@see self::resolveLateBound()}.
 */
final class LateStaticType implements TypeInterface
{
    public function __construct(
        public readonly LateBindingKeyword $keyword,
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
        return [];
    }

    public function isNullable(): bool
    {
        return false;
    }

    public function resolveLateBound(LateBinding $binding): TypeInterface
    {
        $class = $binding->classFor($this->keyword);
        return $class === null ? $this : new ClasslikeType($class);
    }

    public function equals(TypeInterface $other): bool
    {
        return $other instanceof self && $this->keyword === $other->keyword;
    }
}
