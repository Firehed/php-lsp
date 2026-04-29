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
        if (count($this->members) === 2) {
            $nullIndex = null;
            foreach ($this->members as $i => $member) {
                if ($member instanceof PrimitiveType && $member->format() === 'null') {
                    $nullIndex = $i;
                    break;
                }
            }
            if ($nullIndex !== null) {
                $otherIndex = $nullIndex === 0 ? 1 : 0;
                $other = $this->members[$otherIndex];
                if (!$other instanceof IntersectionType) {
                    return '?' . $other->format();
                }
            }
        }

        $parts = array_map(function (Type $member): string {
            $formatted = $member->format();
            if ($member instanceof IntersectionType) {
                return '(' . $formatted . ')';
            }
            return $formatted;
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
}
