<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Fixtures\Attributes\NoConstructorAttribute;
use Fixtures\Attributes\Route;

class AttributeGroupedCompletion
{
    #[NoConstructorAttribute, Rou/*|attr_grouped*/]
    public function trigger(): void
    {
    }
}
