<?php

declare(strict_types=1);

namespace Fixtures\Resolution;

class ArgumentBoundaries
{
    public function target(string $name, int $count = 0): void
    {
    }

    public function boundaries(): void
    {
        $this->target(abc/*|typing_first*/);
        $this->target('a' /*|after_first_with_space*/);
        $this->target('a', /*|after_comma*/);
        $this->target('a',/*|after_comma_no_space*/);
        $this->target('a', cou/*|typing_second*/);
    }
}
