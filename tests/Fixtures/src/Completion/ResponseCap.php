<?php

declare(strict_types=1);

namespace Fixtures\Completion;

// More core PHP functions start with `s` than a completion response holds, and
// most sort before this one by name, so it reaches the response only by ranking
// as a current-namespace symbol.
function summarizeTotals(): void
{
}

class ResponseCap
{
    public function trigger(): void
    {
        $x = s/*|response_cap*/
    }
}
