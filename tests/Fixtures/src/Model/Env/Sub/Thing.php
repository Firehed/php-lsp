<?php

declare(strict_types=1);

namespace Fixtures\Model\Env\Sub;

/**
 * A grandchild of the imported Env namespace, reachable as Env\Sub\Thing, so
 * navigation from an imported prefix can descend more than one level.
 */
class Thing
{
}
