<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * The identity of a property: the {@see ClasslikeName} that owns it and the
 * local name. `PropertyName(C, "foo")` and `PropertyName(D, "foo")` are
 * distinct identities.
 */
final readonly class PropertyName implements ClasslikeOwnedNameInterface
{
    public function __construct(
        public ClasslikeName $owner,
        public string $name,
    ) {
    }
}
