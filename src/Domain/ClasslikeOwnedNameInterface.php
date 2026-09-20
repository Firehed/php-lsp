<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * A name that belongs to a class-like: a method, a property, a class-like
 * constant, or an enum case. The {@see ClasslikeName} identifies the owning
 * class-like; the local name string identifies the member within it.
 *
 * Alias rule: `use T { bar as baz; }` on class C produces `MethodName(C, "baz")`.
 * The aliased-from identity `MethodName(T, "bar")` is recorded as
 * `Info::$aliasedFrom` metadata when a feature demands it.
 */
interface ClasslikeOwnedNameInterface
{
    public string $name { get; }

    public ClasslikeName $owner { get; }
}
