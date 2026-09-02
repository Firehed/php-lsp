<?php

declare(strict_types=1);

namespace Fixtures\Intersection;

use Fixtures\Domain\Entity;
use Fixtures\Domain\Person;

/**
 * Intersection receiver where each constituent declares a distinct member:
 * Entity contributes getId(), Person contributes getName(). A value of the
 * intersection satisfies both, so either member must resolve.
 */
class IntersectionReceiver
{
    public function triggerPersonMember(Entity&Person $value): void
    {
        $value->getName(); //hover:intersection_person_member
    }

    public function triggerEntityMember(Entity&Person $value): void
    {
        $value->getId(); //hover:intersection_entity_member
    }

    public function completePersonMember(Entity&Person $value): void
    {
        $value->/*|intersection_completion*/
    }

    public function signaturePersonMember(Entity&Person $value): void
    {
        $value->getName(/*|intersection_signature*/);
    }
}
