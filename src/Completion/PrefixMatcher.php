<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

/**
 * Case-insensitive prefix matching shared by completion sources.
 */
final class PrefixMatcher
{
    public static function matches(string $name, string $prefix): bool
    {
        return $prefix === '' || str_starts_with(strtolower($name), strtolower($prefix));
    }
}
