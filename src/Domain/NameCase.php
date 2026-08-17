<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Whether names are matched letter-for-letter, and how to key one if not.
 *
 * Which rule a name follows is {@see NameKind}'s question for symbols and
 * {@see MemberKind}'s for class-like members. Applying it is the same operation
 * either way, so it is implemented once, here.
 */
enum NameCase
{
    case Insensitive;

    case Sensitive;

    public function normalize(string $name): string
    {
        return $this === self::Insensitive ? strtolower($name) : $name;
    }
}
