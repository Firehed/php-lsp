<?php

declare(strict_types=1);

namespace Fixtures\IncompleteCode;

use Fixtures\Domain\User as AliasedUser;

class IncompleteAliasedParam
{
    public function test(AliasedUser $user): void
    {
        while ($user->/*|aliased_param*/
    }
}

class IncompleteAliasedStatic
{
    public function testStatic(): void
    {
        /*brace*/ (AliasedUser::/*|aliased_static_access*/
    }
}
