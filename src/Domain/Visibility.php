<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Member visibility level.
 */
enum Visibility: string implements Formattable
{
    case Private = 'private';
    case Protected = 'protected';
    case Public = 'public';

    /**
     * Raises this visibility to the given floor, returning whichever is more
     * restrictive (more public).
     */
    public function atLeast(self $floor): self
    {
        return $this->rank() >= $floor->rank() ? $this : $floor;
    }

    public function isAccessibleFrom(self $minimumRequired): bool
    {
        return $this->rank() >= $minimumRequired->rank();
    }

    public function format(): string
    {
        return $this->value;
    }

    /**
     * Ordinal ranking used for accessibility comparisons: more public is higher.
     */
    private function rank(): int
    {
        return match ($this) {
            self::Private => 0,
            self::Protected => 1,
            self::Public => 2,
        };
    }
}
