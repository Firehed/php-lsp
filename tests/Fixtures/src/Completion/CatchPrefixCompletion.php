<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Fixtures\Exception\AppException;

class CatchPrefixCompletion
{
    public function trigger(): void
    {
        try {
            $value = 1;
        } catch (A/*|catch_a_prefix*/
    }
}
