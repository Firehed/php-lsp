<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * One `as` adaptation in a class's `use TraitX, TraitY { ... }` block.
 *
 * PHP's trait `as` clause exposes a trait method on the using class under a new
 * name, a new visibility, or both. The original method also remains available
 * unless excluded by an `insteadof` clause.
 */
final readonly class TraitAlias
{
    public function __construct(
        /**
         * The trait the aliased method is drawn from. Null when the class
         * writes `method as newName` without naming a trait (PHP resolves the
         * trait at member lookup time).
         */
        public ?ClassName $trait,
        public string $method,
        /** Null when the alias only changes visibility. */
        public ?string $newName,
        /** Null when the alias only renames. */
        public ?Visibility $newVisibility,
    ) {
    }
}
