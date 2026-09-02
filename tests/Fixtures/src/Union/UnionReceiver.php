<?php

declare(strict_types=1);

namespace Fixtures\Union;

use Fixtures\Domain\Entity;
use Fixtures\Domain\Person;

/**
 * Union receiver where each constituent declares a distinct member:
 * Entity contributes getId(), Person contributes getName(). Either member
 * must resolve regardless of constituent order.
 */
class UnionReceiver
{
    public function triggerPersonMember(Entity|Person $value): void
    {
        $value->getName(); //hover:union_person_member
    }

    public function triggerEntityMember(Entity|Person $value): void
    {
        $value->getId(); //hover:union_entity_member
    }

    public function completePersonMember(Entity|Person $value): void
    {
        $value->/*|union_completion*/
    }

    public function signaturePersonMember(Entity|Person $value): void
    {
        $value->getName(/*|union_signature*/);
    }
}
