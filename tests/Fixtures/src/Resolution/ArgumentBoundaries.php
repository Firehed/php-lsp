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
        $this->target(/*|positional*/'a');
        $this->target(name: /*|named_value*/'a');
        $this->target(name: 'a', /*|next_argument*/count: 1);
        $this->target(name: 'a'/*|after_string_value*/);
        $this->target(name: 'a', count: 1/*|after_numeric_value*/);
        $this->target(name/*|after_name*/: 'a');
        $this->target(name:/*|after_colon*/ 'a');
        boundaryTarget('a', count: /*|function_named_value*/1);
        /*|outside_call*/
    }
}
