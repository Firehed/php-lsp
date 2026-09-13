<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

final readonly class IntersectionType implements TypeInterface
{
    /**
     * @param list<TypeInterface> $members
     */
    public function __construct(
        private array $members,
    ) {
    }

    public function format(): string
    {
        $parts = array_map(
            fn (TypeInterface $member): string => $member->format(),
            $this->members,
        );
        return implode('&', $parts);
    }

    public function getResolvableClassNames(): array
    {
        $classNames = [];
        foreach ($this->members as $member) {
            $classNames = array_merge($classNames, $member->getResolvableClassNames());
        }
        return $classNames;
    }

    public function isNullable(): bool
    {
        return false;
    }

    public function resolveLateBound(string $callingClass, bool $declaringClassIsTrait = false): TypeInterface
    {
        $resolved = array_map(
            fn (TypeInterface $member) => $member->resolveLateBound($callingClass, $declaringClassIsTrait),
            $this->members,
        );
        return new self($resolved);
    }

    public function valueType(): ?TypeInterface
    {
        $shared = null;
        foreach ($this->members as $member) {
            $memberValue = $member->valueType();
            if ($memberValue === null) {
                return null;
            }
            if ($shared === null) {
                $shared = $memberValue;
                continue;
            }
            if (!$shared->equals($memberValue)) {
                return null;
            }
        }
        return $shared;
    }

    public function equals(TypeInterface $other): bool
    {
        if (!$other instanceof self) {
            return false;
        }
        if (count($this->members) !== count($other->members)) {
            return false;
        }
        foreach ($this->members as $i => $member) {
            if (!$member->equals($other->members[$i])) {
                return false;
            }
        }
        return true;
    }
}
