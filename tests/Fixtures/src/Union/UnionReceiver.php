<?php

declare(strict_types=1);

namespace Fixtures\Union;

use Fixtures\Domain\Entity;
use Fixtures\Domain\Person;

/**
 * Member access on a union-typed receiver. Only Person declares getName();
 * only Entity declares getId(). Every positional handler and completion must
 * find each member regardless of the constituent order in the union.
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
