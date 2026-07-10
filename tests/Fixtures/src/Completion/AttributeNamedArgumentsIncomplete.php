<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Fixtures\Attributes\Route;

class AttributeNamedArgumentsIncomplete
{
    #[Route(/*|attr_arg_incomplete*/
    public function trigger(): void
    {
    }
}
