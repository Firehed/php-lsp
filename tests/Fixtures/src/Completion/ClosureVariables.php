<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class ClosureVariables
{
    public function getClosureArray(): array
    {
        $a = [
            'x' => function () { $logger = 1; return $logger; },
            'y' => function () { $siteDir = 2; $s/*|closure_scope_isolated*/ },
        ];
        return $a;
    }
}
