<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

class TraitAdaptationUser
{
    use ConflictingTraitA, ConflictingTraitB {
        ConflictingTraitA::conflictMethod insteadof ConflictingTraitB;
        ConflictingTraitB::conflictMethod as conflictMethodFromB;
        ConflictingTraitB::onlyInB as protected protectedOnlyInB;
    }
}
