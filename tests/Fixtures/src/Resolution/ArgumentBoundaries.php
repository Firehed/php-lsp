<?php

declare(strict_types=1);

namespace Fixtures\Resolution;

class ArgumentBoundaries
{
    public function target(string $name, int $count = 0): void
    {
    }

    public function boundaries(int $count): void
    {
        $this->target(abc/*|typing_first*/);
        $this->target('a' /*|after_first_with_space*/);
        $this->target('a', /*|after_comma*/);
        $this->target('a',/*|after_comma_no_space*/);
        $this->target('a', cou/*|typing_second*/);
        $this->target(name: 'a', /*|after_named*/);
        $this->target('a', $count + /*|inside_second_value*/1);
        /*|outside_call*/
    }
}
