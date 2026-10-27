<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class SelfAssign
{
    public function reassignFromItself(ChainableUser $value): void
    {
        $value = $value->getName();
        $value->/*|self_assigned*/
    }
}
