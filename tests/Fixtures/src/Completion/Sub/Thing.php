<?php

declare(strict_types=1);

namespace Fixtures\Completion\Sub;

class Thing
{
    public static function build(): self
    {
        return new self();
    }
}
