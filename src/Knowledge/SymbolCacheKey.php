<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\CacheKey;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Resolution\NameKind;

/**
 * The cache key a backend stores a resolved symbol under (RFC 1 §5.3).
 *
 * A name alone does not identify a symbol: PHP's three symbol namespaces are
 * independent, so one file may declare both a class and a function called `Foo`.
 * The kind is therefore part of the key, not just of the query — without it a
 * cached class would be served to a function lookup.
 */
final class SymbolCacheKey
{
    public static function for(QualifiedName $name, NameKind $kind): string
    {
        return CacheKey::from($kind->name . '|' . $kind->normalize($name));
    }
}
