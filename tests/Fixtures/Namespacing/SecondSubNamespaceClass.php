<?php

declare(strict_types=1);

namespace App\Deep;

/**
 * A second class sharing the short name `Thing` with {@see \App\Sub\Thing}, in a
 * different sub-namespace. From `namespace App`, each resolves under a distinct
 * relative reference (`Sub\Thing` vs `Deep\Thing`); completion must keep them
 * apart rather than collapsing both onto an ambiguous bare `Thing`.
 */
class Thing
{
}
