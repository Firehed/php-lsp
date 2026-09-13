<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

final readonly class UnionType implements TypeInterface
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
        // Format 2-member nullable unions as ?T instead of T|null
        if (count($this->members) === 2 && $this->isNullable()) {
            $other = $this->members[0]->isNullable() ? $this->members[1] : $this->members[0];
            // Don't use ?() for DNF types - (A&B)|null must stay as-is
            if (!$other instanceof IntersectionType) {
                return '?' . $other->format();
            }
        }

        $parts = array_map(function (TypeInterface $member): string {
            if ($member instanceof IntersectionType) {
                return '(' . $member->format() . ')';
            }
            return $member->format();
        }, $this->members);
        return implode('|', $parts);
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
        foreach ($this->members as $member) {
            if ($member->isNullable()) {
                return true;
            }
        }
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
