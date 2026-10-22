<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Which class-likes a position admits by name.
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

    /** Only traits (e.g. after `use` in a class body) */
    case Trait_;
}
