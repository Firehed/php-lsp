<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

final readonly class IntersectionType implements Type
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
        $parts = array_map(
            fn (Type $member): string => $member->format(),
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

    public function resolveLateBound(string $callingClass, bool $declaringClassIsTrait = false): Type
    {
        $resolved = array_map(
            fn (Type $member) => $member->resolveLateBound($callingClass, $declaringClassIsTrait),
            $this->members,
        );
        return new self($resolved);
    }

    public function valueType(): ?Type
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
            if (serialize($shared) !== serialize($memberValue)) {
                return null;
            }
        }
        return $shared;
    }
}
