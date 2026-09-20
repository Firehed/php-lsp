<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * The identity of a class-owned constant: the {@see ClasslikeName} that owns
 * it and the local name. `ClasslikeConstantName(C, "FOO")` and
 * `ClasslikeConstantName(D, "FOO")` are distinct identities.
 */
final readonly class ClasslikeConstantName implements ClasslikeOwnedNameInterface
{
    public function __construct(
        public ClasslikeName $owner,
        public string $name,
    ) {
    }
}
