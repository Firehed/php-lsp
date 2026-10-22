<?php

declare(strict_types=1);

namespace Fixtures\Position;

use Fixtures\Domain\User;

class Positions
{
    public function method(int $first, /*|second_parameter*/$second): void
    {
        /*|empty_body*/
        $label = "count {$first} ite/*|in_interpolated*/ms";
        User::create('name');
        $this->method(first: 1, second: 2);
        printf('%s %s', 'a', /*|after_two_positional*/'b');
    }
}
