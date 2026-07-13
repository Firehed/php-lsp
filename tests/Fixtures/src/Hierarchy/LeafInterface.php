<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

use Countable;

/**
 * Extends multiple interfaces, one of them a built-in resolved via reflection.
 */
interface LeafInterface extends MiddleInterface, Countable
{
    public function leafMethod(): string;
}
