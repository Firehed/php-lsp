<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

/**
 * The intent behind a class-name completion, which determines both the index
 * kinds queried and the resolution predicate applied.
 *
 * Adding a context-specific filter (e.g. interfaces-only after `implements`,
 * traits-only after `use` in a class body — see issue #298) is a matter of
 * adding a case here and a row to the mapping in {@see ClassCandidates}; no
 * caller or structural change is required.
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
}
