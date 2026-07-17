<?php

declare(strict_types=1);

namespace Fixtures\Model\Env;

/**
 * A child of the imported Env namespace, reachable as Env\Repository. Its method
 * exists to prove members are never offered as `new`/class candidates.
 */
class Repository
{
    public function persist(): void
    {
    }
}
