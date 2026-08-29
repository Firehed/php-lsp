<?php

declare(strict_types=1);

// Last-good seed for the broken variant at src/IncompleteCode/VeryBroken.php.
namespace Fixtures\IncompleteCode;

class VeryBroken
{
    private string $name;

    public function getName(): string
    {
        return $this->name;
    }
}
