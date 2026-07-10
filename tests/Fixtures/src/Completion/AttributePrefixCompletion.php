<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Fixtures\Attributes\Route;

class AttributePrefixCompletion
{
    #[Rou/*|attr_prefix*/]
    public function trigger(): void
    {
    }
}
