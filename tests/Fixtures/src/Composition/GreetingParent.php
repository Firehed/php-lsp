<?php

declare(strict_types=1);

namespace Fixtures\Composition;

class GreetingParent
{
    public function hello(): string
    {
        return 'parent';
    }
}
