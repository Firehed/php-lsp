<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Resolution\CodeResolver;

/**
 * The intent behind a class-name completion, which determines the resolution
 * predicate applied to a candidate.
 *
 * The predicate lives here, on the filter, so every source of class-like
 * candidates — the workspace index, imports, and the namespace catalog — applies
 * the same rule rather than keeping its own copy. Adding a context-specific
 * filter (e.g. interfaces-only after `implements`, traits-only after `use` in a
 * class body — see issue #298) is a matter of adding a case here and a match arm
 * in {@see accepts()}; no caller or structural change is required.
 */
enum ClassCandidateFilter
{
    /** Any class-like: classes, interfaces, traits, enums (expression positions) */
    case Any;

    /** Only types that can be instantiated with `new` */
    case Instantiable;

    /** Only types valid as a type hint (traits excluded) */
    case TypeHint;

    /** Only interfaces (e.g. after `implements`) */
    case Interface_;

    /** Only extendable classes: non-final classes (e.g. after `class X extends`) */
    case ExtendableClass;

    /** Only catchable types: Throwable and its subtypes (e.g. after `catch (`) */
    case Throwable;

    /** Only attribute classes (e.g. in a `#[...]` position) */
    case Attribute;

    /**
     * Whether a class-like is valid in this position, resolved through the
     * {@see CodeResolver} predicates (which read the caching class repository).
     */
    public function accepts(ClassName $className, CodeResolver $codeResolver): bool
    {
        return match ($this) {
            self::Any => true,
            self::Instantiable => $codeResolver->isInstantiable($className),
            self::TypeHint => $codeResolver->isValidTypeHint($className),
            self::Interface_ => $codeResolver->isInterface($className),
            self::ExtendableClass => $codeResolver->isExtendableClass($className),
            self::Throwable => $codeResolver->isThrowable($className),
            self::Attribute => $codeResolver->isAttribute($className),
        };
    }
}
