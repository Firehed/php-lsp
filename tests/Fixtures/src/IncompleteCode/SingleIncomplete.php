<?php

declare(strict_types=1);

namespace Fixtures\IncompleteCode;

class SingleIncomplete
{
    private string $name;

    public function getName(): string
    {
        return $this->name;
    }

    public function test(): void
    {
        if ($this->/*|this_in_if*/
    }
}
