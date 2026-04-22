<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Member visibility level.
 */
enum Visibility: int
{
    case Private = 0;
    case Protected = 1;
    case Public = 2;

    public function isAccessibleFrom(self $minimumRequired): bool
    {
        return $this->value >= $minimumRequired->value;
    }

    public function toKeyword(): string
    {
        return match ($this) {
            self::Private => 'private',
            self::Protected => 'protected',
            self::Public => 'public',
        };
    }
}
