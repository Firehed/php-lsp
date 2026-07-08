<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Fixtures\Domain\Entity;
use Fixtures\Domain\Person;

/**
 * Fixture for member access on an intersection-typed value (#304).
 *
 * Entity contributes getId(); Person contributes getName() and getAge().
 * Completion after $value-> should offer members of BOTH constituents.
 */
class IntersectionAccess
{
    public function trigger(Entity&Person $value): void
    {
        $value->/*|intersection_access*/
    }
}
