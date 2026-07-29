<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Cache;

/**
 * Derives a PSR-16-safe cache key from an already-normalized identifier.
 *
 * PSR-16 reserves the characters `{}()/\@:` and only guarantees support for
 * keys up to 64 characters. Class FQNs and namespaces carry backslashes and can
 * exceed that length, so they cannot be handed to a conformant cache verbatim.
 * Hashing maps any normalized identifier into the safe hexadecimal alphabet at a
 * fixed length; callers own semantic normalization (e.g. lowercasing for PHP's
 * case-insensitive names) before deriving the key.
 */
final class CacheKey
{
    public static function from(string $normalizedIdentifier): string
    {
        // xxh128 (xxHash): a fast non-cryptographic hash; hex output is PSR-16-safe and within the 64-char key bound.
        return hash('xxh128', $normalizedIdentifier);
    }
}
