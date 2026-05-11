<?php

declare(strict_types=1);

namespace Fixtures\IncompleteCode;

class CompleteInControl
{
    private string $name = '';

    public function getName(): string
    {
        return $this->name;
    }

    public function testIf(): void
    {
        if ($this->name) {} //hover:prop_in_if
    }

    public function testWhile(): void
    {
        while ($this->getName()) {} //hover:method_in_while
    }
}
