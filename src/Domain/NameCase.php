<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Whether a name is matched letter-for-letter, and how to key it if not.
 *
 * Which rule applies is a question about a kind of name, and is answered by
 * {@see NameKind} for symbols and {@see MemberKind} for class-like members.
 * Applying it is the same operation either way, so it is written once here.
 */
enum NameCase
{
    case Insensitive;

    case Sensitive;

    public function fold(string $name): string
    {
        return $this === self::Insensitive ? strtolower($name) : $name;
    }
}
