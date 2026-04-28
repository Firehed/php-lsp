<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

final readonly class NamedType implements Type
{
    /**
     * @param list<Type> $typeArguments
     */
    public function __construct(
        private string $name,
        private bool $isBuiltin,
        /** @phpstan-ignore property.onlyWritten (for future generics support) */
        private array $typeArguments = [],
    ) {
    }

    public function format(): string
    {
        return $this->name;
    }

    public function getResolvableClassNames(): array
    {
        if ($this->isBuiltin) {
            return [];
        }
        /** @var class-string $name */
        $name = $this->name;
        return [new ClassName($name)];
    }

    public function isNullable(): bool
    {
        return false;
    }
}
