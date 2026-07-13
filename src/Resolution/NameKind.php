<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

/**
 * The category of symbol a name refers to, which determines how PHP resolves it
 * when unqualified.
 *
 * Only three categories exist because only three participate in name
 * resolution: class-likes, functions, and constants. Members and variables are
 * reached through an already-resolved type, so namespaces never enter.
 */
enum NameKind
{
    case ClassLike;

    case Constant;

    case Function_;

    /**
     * Whether an unqualified name of this kind falls back to the global
     * namespace at runtime when the current namespace has no such symbol.
     *
     * PHP manual, name resolution rule 7: the fallback applies to functions and
     * constants. Rule 6: an unqualified class-like name prepends the current
     * namespace and never falls back.
     */
    public function fallsBackToGlobal(): bool
    {
        return match ($this) {
            self::ClassLike => false,
            self::Constant, self::Function_ => true,
        };
    }

    /**
     * Whether names of this kind are matched case-sensitively. Class and
     * function names are case-insensitive in PHP; constant names are not.
     */
    public function isCaseSensitive(): bool
    {
        return $this === self::Constant;
    }
}
