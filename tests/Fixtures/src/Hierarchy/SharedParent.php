<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

class SharedParent
{
    public function shared(): string
    {
        return 'parent';
    }
}
