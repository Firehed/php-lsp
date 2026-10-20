<?php

declare(strict_types=1);

namespace Fixtures\Resolution;

final class ClosureParameter
{
    public function withClosure(): callable
    {
        return function (string $captured): string { //jtd:closure_param captured
            return $captured;
        };
    }
}
