<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

// PHP treats class names case-insensitively, so the `insteadof` clause's
// namespace casing may differ from the `use` clause's. The declared identity
// is one class either way, and the exclusion must still apply.
class TraitAdaptationMixedCaseUser
{
    use ConflictingTraitA, ConflictingTraitB {
        \fixtures\hierarchy\ConflictingTraitA::conflictMethod insteadof \fixtures\hierarchy\ConflictingTraitB;
    }
}
