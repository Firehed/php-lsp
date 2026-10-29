<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

/**
 * The trait's abstract method is bound only after inheritance, so the
 * parent's concrete method stays.
 */
class AbstractTraitOverParentUser extends SharedParent
{
    use AbstractSharedTrait;
}
