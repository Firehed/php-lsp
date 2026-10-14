<?php

declare(strict_types=1);

namespace Fixtures\Precedence;

/**
 * Also declared under the PSR-4 tree. Composer requires this file before the
 * autoloader is ever asked, so this declaration is the one PHP loads.
 */
final class Bootstrapped
{
}
