<?php

declare(strict_types=1);

namespace Fixtures\Position;

use Fixtures\Domain\User/*|import*/;

class Symbols
{
    public function method/*|declared_method*/(User/*|parameter_type*/ $user/*|parameter*/, int/*|builtin_type*/ $count/*|second_parameter*/ = 1): void
    {
        $user/*|variable*/->getName/*|member*/();
        User::create/*|static_member*/('name');
        User/*|static_receiver*/::create('name');
        strlen/*|called*/('x');
        $this->method(user/*|argument_name*/: $user, count: 1);
        new User(name/*|constructor_argument*/: 'x');
        echo PHP_EOL/*|constant*/;
        // a comment/*|comment*/
    }

    public function unwrittenMember(User $user): void
    {
        $user->/*|unwritten_member*/
    }
}
