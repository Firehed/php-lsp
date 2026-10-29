<?php

declare(strict_types=1);

namespace Fixtures\Composition;

class SharedParent
{
    public function shared(): string
    {
        return 'parent';
    }
}
