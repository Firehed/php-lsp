<?php

declare(strict_types=1);

namespace Fixtures\Utility;

class BindingSites
{
    public function method(string $param): void
    {
        $assigned = 1;
        foreach ([1, 2] as $key => $value) {
            $inLoop = $value;
        }
        try {
            $inTry = 1;
        } catch (\Throwable $caught) {
            $inCatch = 1;
        }
        if (true) {
            $inIf = 1;
        } else {
            $inElse = 1;
        }
        $closure = function ($closureParam) use ($assigned) {
            $insideClosure = 1;
            return $closureParam + $assigned;
        };
        $arrow = fn($arrowParam) => $arrowParam + $param;
        function nestedFunction(): void
        {
            $insideNested = 1;
        }
        $afterEverything = 1;
    }
}
