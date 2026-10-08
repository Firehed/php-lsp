<?php

declare(strict_types=1);

namespace Fixtures\Resolution;

use Fixtures\Domain;
use Fixtures\Domain\User as Account;
use Fixtures\Enum\{Priority};

use function Fixtures\Utility\formatName;

class CursorTextResolution
{
    #[Account(/*|attribute*/)]
    public function run(): void
    {
        Account::create(/*|aliased_static*/);
        Priority::fromScore(/*|group_static*/);
        new Account(/*|aliased_new*/);
        helper(/*|namespaced_function*/);
        formatName(/*|imported_function*/);
        Domain\User::create(/*|qualified_static*/);
        Domain\helper(/*|qualified_function*/);
        namespace\CursorTextResolution::run(/*|relative_static*/);
        namespace\helper(/*|relative_function*/);
    }
}
