<?php

declare(strict_types=1);

namespace Fixtures\IncompleteCode;

use Fixtures\Domain\{User, Team};

class IncompleteGroupImportParam
{
    public function testUser(User $user): void
    {
        while ($user->/*|group_user_param*/
    }
}

class IncompleteGroupImportParamSecond
{
    public function testTeam(Team $team): void
    {
        while ($team->/*|group_team_param*/
    }
}
