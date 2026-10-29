<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

/**
 * Requires methods rather than providing them.
 */
trait AbstractRequirements
{
    abstract public function inheritedMethod(): string;

    abstract public function conflictMethod(): string;
}
