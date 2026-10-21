<?php

declare(strict_types=1);

namespace Fixtures\Dnf;

use Fixtures\Domain\Entity;
use Fixtures\Domain\Person;

/**
 * DNF receiver where each constituent of the intersection declares a distinct
 * member: Entity contributes getId(), Person contributes getName().
 */
class DnfReceiver
{
    public function triggerPersonMember((Entity&Person)|null $value): void
    {
        $value->getName(); //hover:dnf_person_member
    }

    public function triggerEntityMember((Entity&Person)|null $value): void
    {
        $value->getId(); //hover:dnf_entity_member
    }

    public function completePersonMember((Entity&Person)|null $value): void
    {
        $value->/*|dnf_completion*/
    }

    public function signaturePersonMember((Entity&Person)|null $value): void
    {
        $value->getName(/*|dnf_signature*/);
    }
}
