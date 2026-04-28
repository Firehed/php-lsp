<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Type-safe wrapper for fully-qualified class names.
 */
final readonly class ClassName implements Type
{
    /**
     * @param class-string $fqn
     * @param list<Type> $typeArguments
     */
    public function __construct(
        public string $fqn,
        /** @phpstan-ignore property.onlyWritten (for future generics support) */
        private array $typeArguments = [],
    ) {
    }

    public function format(): string
    {
        return $this->fqn;
    }

    /**
     * @return list<ClassName>
     */
    public function getResolvableClassNames(): array
    {
        return [$this];
    }

    public function isNullable(): bool
    {
        return false;
    }

    public function shortName(): string
    {
        $lastSeparator = strrpos($this->fqn, '\\');
        if ($lastSeparator === false) {
            return $this->fqn;
        }
        return substr($this->fqn, $lastSeparator + 1);
    }

    public function namespace(): ?string
    {
        $lastSeparator = strrpos($this->fqn, '\\');
        if ($lastSeparator === false) {
            return null;
        }
        return substr($this->fqn, 0, $lastSeparator);
    }

    public function equals(self $other): bool
    {
        return strcasecmp($this->fqn, $other->fqn) === 0;
    }
}
