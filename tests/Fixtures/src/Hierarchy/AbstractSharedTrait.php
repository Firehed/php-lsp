<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

/**
 * Requires a method rather than providing one.
 */
trait AbstractSharedTrait
{
    abstract public function shared(): string;
}
