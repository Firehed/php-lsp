<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Fixtures\Attributes\Route;

class AttributeNamedArguments
{
    #[Route(/*|attr_arg_empty*/)]
    public function emptyArgs(): void
    {
    }

    #[Route('/x', /*|attr_arg_second*/)]
    public function afterPositional(): void
    {
    }
}
