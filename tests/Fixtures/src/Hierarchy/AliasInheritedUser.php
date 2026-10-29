<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

/**
 * The parent's aliases are its methods, so this class inherits them; the
 * trait's abstract `conflictMethod` leaves the inherited one in place.
 */
class AliasInheritedUser extends TraitAdaptationUser
{
    use AbstractOverConflictingTraitA;
}
