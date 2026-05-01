<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class ParentWithMethods
{
    public function __construct()
    {
    }

    protected function greet(): string
    {
        return 'Hello';
    }

    protected function goodbye(): string
    {
        return 'Bye';
    }
}
