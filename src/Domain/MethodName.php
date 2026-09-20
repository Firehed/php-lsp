<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * The identity of a method: the {@see ClasslikeName} that owns it and the
 * local name. `MethodName(C, "foo")` and `MethodName(D, "foo")` are distinct
 * identities; the `equals()` check compares both fields.
 */
final class MethodName implements ClasslikeOwnedNameInterface
{
    public function __construct(
        public readonly ClasslikeName $owner,
        public readonly string $name,
    ) {
    }

    public function equals(self $other): bool
    {
        return $this->owner->equals($other->owner)
            && MemberKind::Method->normalize($this->name) === MemberKind::Method->normalize($other->name);
    }
}
