<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

/**
 * An `as` alias that names no trait. PHP resolves the source at member lookup,
 * so the walk must scan the used traits itself.
 */
class TraitNamelessAliasUser
{
    use ConflictingTraitA {
        onlyInA as renamedOnlyInA;
    }
}
