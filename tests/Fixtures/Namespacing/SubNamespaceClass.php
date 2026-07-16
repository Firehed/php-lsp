<?php

declare(strict_types=1);

namespace App\Sub;

/**
 * A class in a sub-namespace of the cursor's. From `namespace App`, it resolves
 * as the relative reference `Sub\Thing`; completion must offer it that way, not
 * as a bare `Thing` (which would resolve to the nonexistent `App\Thing`).
 */
class Thing
{
}
