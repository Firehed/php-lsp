<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class ChainableUser
{
    public function getName(): Name
    {
        return new Name('');
    }
}
