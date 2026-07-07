<?php

declare(strict_types=1);

namespace Fixtures\Utility;

use Fixtures\Domain\{User, Team};
use Fixtures\Enum\{Status, Priority as Pri};

class GroupUseExample
{
    public function test(User $user, Team $team): Status
    {
        return Status::Active;
    }
}
