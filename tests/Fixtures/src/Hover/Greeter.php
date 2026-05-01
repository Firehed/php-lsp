<?php

declare(strict_types=1);

namespace Fixtures\Hover;

class Greeter
{
    /** Greets a person. */
    public function greet(string $name, string $prefix = 'Hello'): string
    {
        return "$prefix, $name!";
    }
}
