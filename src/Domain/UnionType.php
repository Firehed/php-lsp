<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

final readonly class UnionType implements Type
{
    /**
     * @param list<Type> $members
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

        $parts = array_map(function (Type $member): string {
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

    public function resolveLateBound(string $callingClass): Type
    {
        $resolved = array_map(
            fn (Type $member) => $member->resolveLateBound($callingClass),
            $this->members,
        );
        return new self($resolved);
    }
}
