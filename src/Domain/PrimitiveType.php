<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

final readonly class PrimitiveType implements Type
{
    /**
     * @param list<Type> $typeArguments
     */
    public function __construct(
        private string $name,
        /** @phpstan-ignore property.onlyWritten (for future generics support) */
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
}
