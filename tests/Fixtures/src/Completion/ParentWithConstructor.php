<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class ParentWithConstructor
{
    public function __construct(string $name)
    {
    }

    protected function greet(): string
    {
        return 'Hello';
    }
}
