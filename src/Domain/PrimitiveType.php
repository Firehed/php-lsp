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

    /**
     * @param list<TypeInterface> $typeArguments
     */
    public function __construct(
        private string $name,
        private array $typeArguments = [],
    ) {
    }

    public function format(): string
    {
        return $this->name;
    }

    /**
     * @return list<ClassName>
     */
    public function getResolvableClassNames(): array
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

    public function valueType(): ?TypeInterface
    {
        return $this->typeArguments[0] ?? null;
    }

    public function equals(TypeInterface $other): bool
    {
        if (!$other instanceof self) {
            return false;
        }
        if ($this->name !== $other->name) {
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
}
