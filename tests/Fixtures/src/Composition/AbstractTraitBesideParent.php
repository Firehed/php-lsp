<?php

declare(strict_types=1);

namespace Fixtures\Composition;

/**
 * A trait's abstract method is bound only after inheritance, so the parent's
 * concrete method stays.
 */
class AbstractTraitBesideParent extends SharedParent
{
    use RequiresShared;
}
