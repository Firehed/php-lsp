<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Fixtures\Attributes\NoConstructorAttribute;
use Fixtures\Attributes\Route;
use Fixtures\Domain\Entity;
use Fixtures\Domain\User;
use Fixtures\Enum\Status;
use Fixtures\Traits\SingletonTrait;

class AttributeCompletion
{
    #[/*|attr_empty*/]
    public function trigger(): void
    {
    }
}
