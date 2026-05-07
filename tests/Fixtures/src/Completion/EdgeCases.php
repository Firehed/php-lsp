<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Fixtures\Domain\User;

class EdgeCases
{
    public function unresolvedVariable(): void
    {
        $unknownVar->/*|unresolved_var*/
    }

    public function dynamicClassAccess(string $className): void
    {
        $className::/*|dynamic_class*/
    }

    public function unrelatedStaticAccess(): void
    {
        User::/*|unrelated_static*/
    }

    public function notMemberAccess(): void
    {
        $x = 1;
        /*|not_member_access*/
    }
}
