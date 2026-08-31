<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

/**
 * The excluded trait is used before the winning trait, so the walk visits it
 * first. The exclusion guard, not the de-duplication key, is what drops the
 * excluded copy.
 */
class TraitAdaptationReversedUser
{
    use ConflictingTraitB, ConflictingTraitA {
        ConflictingTraitA::conflictMethod insteadof ConflictingTraitB;
    }
}
