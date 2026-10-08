<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

final readonly class PrimitiveType implements TypeInterface
{
    /**
     * PHP built-in and pseudo type names that are not class-like.
     *
     * @var list<string>
     */
    public const NAMES = [
        'string',
        'int',
        'float',
        'bool',
        'array',
        'object',
        'callable',
        'iterable',
        'void',
        'never',
        'mixed',
        'null',
        'true',
        'false',
    ];

    public function __construct(
        private string $name,
    ) {
    }

    public function format(): string
    {
        return $this->name;
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
        return $this->name === 'null';
    }

    public function resolveLateBound(string $callingClass, bool $declaringClassIsTrait = false): TypeInterface
    {
        return $this;
    }

    public function equals(TypeInterface $other): bool
    {
        return $other instanceof self
            && $this->name === $other->name;
    }
}
