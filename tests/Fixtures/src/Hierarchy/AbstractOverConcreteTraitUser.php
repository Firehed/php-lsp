<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

/**
 * The trait it uses resolves its method to its own abstract one, so the
 * parent's concrete method stays.
 */
class AbstractOverConcreteTraitUser extends SharedParent
{
    use AbstractOverConcreteTrait;
}
