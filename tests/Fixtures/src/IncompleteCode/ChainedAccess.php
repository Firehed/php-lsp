<?php

declare(strict_types=1);

namespace Fixtures\IncompleteCode;

use Fixtures\Domain\User;

class ChainedAccess
{
    private User $user;

    public function test(): void
    {
        if ($this->user->/*|chained_in_if*/
    }
}
