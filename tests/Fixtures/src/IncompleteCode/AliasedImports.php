<?php

declare(strict_types=1);

namespace Fixtures\IncompleteCode;

use Fixtures\Domain\User as AliasedUser;
use Fixtures\Namespacing\Models\Widget;
use function Fixtures\Namespacing\Helpers\Widget;

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

class IncompleteCollidingImport
{
    public function testStatic(): void
    {
        /*brace*/ (Widget::/*|colliding_static*/
    }
}

class IncompleteCollidingNew
{
    public function testNew(): void
    {
        new Widget(/*|colliding_new*/
    }
}
