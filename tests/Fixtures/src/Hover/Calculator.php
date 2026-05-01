<?php

declare(strict_types=1);

namespace Fixtures\Hover;

class Calculator
{
    /** Adds two numbers. */
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    /** Multiplies two numbers. */
    public function multiply(int $a, int $b): int
    {
        return $a * $b;
    }

    /** Divides two numbers. */
    public function divide(int $a, int $b): float
    {
        return $a / $b;
    }
}
