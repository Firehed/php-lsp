<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * The identity of a method: the {@see ClasslikeName} that owns it and the
 * local name. `MethodName(C, "foo")` and `MethodName(D, "foo")` are distinct
 * identities.
 */
final readonly class MethodName implements ClasslikeOwnedNameInterface
{
    public function __construct(
        public ClasslikeName $owner,
        public string $name,
    ) {
    }
}
