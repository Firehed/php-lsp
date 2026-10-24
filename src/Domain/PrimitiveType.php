<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * PHP's built-in and pseudo type names that are not class-like.
 */
enum PrimitiveType: string implements TypeInterface
{
    case Array = 'array';
    case Bool = 'bool';
    case Callable = 'callable';
    case False = 'false';
    case Float = 'float';
    case Int = 'int';
    case Iterable = 'iterable';
    case Mixed = 'mixed';
    case Never = 'never';
    case Null = 'null';
    case Object = 'object';
    case String = 'string';
    case True = 'true';
    case Void = 'void';

    public function format(): string
    {
        return $this->value;
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
        return $this === self::Null;
    }

    public function resolveLateBound(LateBinding $binding): TypeInterface
    {
        return $this;
    }

    public function equals(TypeInterface $other): bool
    {
        return $this === $other;
    }
}
