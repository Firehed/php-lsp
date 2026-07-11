<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Fixtures\Domain\User;
use Fixtures\Exception\AppException;
use Fixtures\Exception\ExceptionInterface;

class MultiCatchCompletion
{
    public function trigger(): void
    {
        try {
            $value = 1;
        } catch (AppException | /*|catch_multi*/
    }
}
