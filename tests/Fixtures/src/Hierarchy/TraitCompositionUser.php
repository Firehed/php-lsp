<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

/**
 * A used trait's own alias reaches the class; `insteadof`, matched without
 * regard to case, excludes what a trait got from another trait; and the
 * class's own method wins over an alias of its name.
 */
class TraitCompositionUser
{
    use NestingConflictingTraitA, ConflictingTraitB {
        ConflictingTraitB::CONFLICTMETHOD insteadof NestingConflictingTraitA;
        ConflictingTraitB::onlyInB as ownMethod;
        ConflictingTraitB::onlyInB as final finalOnlyInB;
        NestingConflictingTraitA::aliasedOnlyInA as final;
    }

    public function ownMethod(): string
    {
        return 'own';
    }
}
