<?php

declare(strict_types=1);

namespace Fixtures\LateBinding;

/**
 * `self` in a trait names the class using it (PHP manual, Traits).
 */
trait Linked
{
    public ?self $next = null;
}
