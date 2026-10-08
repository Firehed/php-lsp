<?php

declare(strict_types=1);

namespace Fixtures\Resolution;

use Fixtures\Domain\User as Account;
use Fixtures\Enum\{Priority};

class CursorTextResolution
{
    #[Account(/*|attribute*/)]
    public function run(): void
    {
        Account::create(/*|aliased_static*/);
        Priority::fromScore(/*|group_static*/);
        new Account(/*|aliased_new*/);
        helper(/*|namespaced_function*/);
    }
}
