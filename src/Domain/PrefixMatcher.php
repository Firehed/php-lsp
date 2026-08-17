<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Case-insensitive prefix matching, shared by the completion sources and the index.
 *
 * Insensitive regardless of the name's own {@see NameCase}: a user typing a prefix has
 * not finished the name, so the casing they have typed so far is not yet a claim about
 * the symbol they mean.
 */
final class PrefixMatcher
{
    public static function matches(string $name, string $prefix): bool
    {
        return str_starts_with(
            NameCase::Insensitive->normalize($name),
            NameCase::Insensitive->normalize($prefix),
        );
    }
}
