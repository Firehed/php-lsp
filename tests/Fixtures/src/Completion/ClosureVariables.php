<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class ClosureVariables
{
    public function withClosure(): void
    {
        $fn = function ($param) {
            $localVar = 1;
            $l/*|closure_local*/
        };
    }
}
