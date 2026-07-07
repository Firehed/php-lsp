<?php

declare(strict_types=1);

namespace Fixtures\Utility;

use Fixtures\Domain\User;

class ScopePatterns
{
    public function methodWithThis(): void
    {
        $this->methodWithThis();
    }

    public function methodWithClosure(): void
    {
        $fn = function () {
            $closureVar = 1;
        };
    }

    public function methodWithArrowFunction(): void
    {
        $fn = fn() => $arrowVar = 1;
    }

    public function methodWithClosureUse(): void
    {
        $captured = 1;
        $fn = function () use ($captured) {
            return $captured;
        };
    }
}
