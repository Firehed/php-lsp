<?php

declare(strict_types=1);

namespace Other;

/**
 * A class in a namespace unrelated to the cursor's. From `namespace App`, its
 * only reference is the fully-qualified `\Other\Thing` — there is no unqualified
 * or relative one — so completion must not offer it as a bare `Thing`.
 */
class Thing
{
}
