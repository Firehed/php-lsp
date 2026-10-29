<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

/**
 * A trait that gets its methods from another trait, renaming one.
 */
trait NestingConflictingTraitA
{
    use ConflictingTraitA {
        onlyInA as aliasedOnlyInA;
    }
}
