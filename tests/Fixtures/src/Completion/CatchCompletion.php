<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Fixtures\Domain\Entity;
use Fixtures\Domain\User;
use Fixtures\Enum\Status;
use Fixtures\Exception\AppException;
use Fixtures\Exception\ExceptionInterface;
use Fixtures\Traits\SingletonTrait;

class CatchCompletion
{
    public function trigger(): void
    {
        try {
            $value = 1;
        } catch (/*|catch_empty*/
    }
}
