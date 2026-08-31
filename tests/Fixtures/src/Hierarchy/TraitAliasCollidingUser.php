<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

/**
 * An `as` alias whose new name equals a method inherited from a parent. The
 * alias must replace the walked entry, so the trait-alias method wins.
 */
class TraitAliasCollidingUser extends TraitAliasCollidingParent
{
    use ConflictingTraitA {
        ConflictingTraitA::onlyInA as inheritedMethod;
    }
}
