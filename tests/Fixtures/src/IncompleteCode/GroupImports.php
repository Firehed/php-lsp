<?php

declare(strict_types=1);

namespace Fixtures\IncompleteCode;

use Fixtures\Domain\{User, Team};
use Fixtures\Enum\{Status, Priority as Pri};

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

class IncompleteGroupImportStatic
{
    // Break the AST enough that node finder fails
    public function testStatic(): void
    {
        /*brace*/ (User::/*|group_static_access*/
    }
}

class IncompleteGroupImportAliased
{
    public function testAliased(): void
    {
        if (Pri::/*|group_aliased_static*/
    }
}
