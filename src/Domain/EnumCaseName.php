<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * The identity of an enum case: the {@see ClasslikeName} that owns it and the
 * local name. `EnumCaseName(A, "Foo")` and `EnumCaseName(B, "Foo")` are
 * distinct identities.
 */
final readonly class EnumCaseName implements ClasslikeOwnedNameInterface
{
    public function __construct(
        public ClasslikeName $owner,
        public string $name,
    ) {
    }
}
