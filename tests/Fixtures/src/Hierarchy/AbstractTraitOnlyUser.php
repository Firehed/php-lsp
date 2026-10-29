<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

/**
 * With nothing else providing the method, the trait's abstract one is bound.
 */
abstract class AbstractTraitOnlyUser
{
    use AbstractSharedTrait;
}
