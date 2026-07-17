<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Fixtures\Domain\Entity;
use Fixtures\Domain\User;
use Fixtures\Enum\Status;
use Fixtures\Traits\SingletonTrait;

class InstanceofCompletion
{
    public function trigger(object $value): void
    {
        if ($value instanceof /*|instanceof_empty*/) {
            return;
        }
    }
}
