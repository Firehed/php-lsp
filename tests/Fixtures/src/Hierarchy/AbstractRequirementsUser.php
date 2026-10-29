<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

/**
 * A trait's abstract methods, and aliases of them, only fill a name nothing
 * else provides: `inheritedMethod` stays the parent's, and `conflictMethod`
 * is ConflictingTraitA's.
 */
class AbstractRequirementsUser extends TraitAliasCollidingParent
{
    use AbstractRequirements, ConflictingTraitA {
        AbstractRequirements::conflictMethod as inheritedMethod;
    }
}
