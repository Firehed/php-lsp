<?php

declare(strict_types=1);

namespace App;

// A closure's `use (...)` clause captures variables from the enclosing scope; it
// shares the `use` keyword with an import but is an unrelated construct. Like a
// trait `use`, it must never enter the absolute namespace navigation an import
// `use` triggers (#40): it is a variable position, not a class-reference one.
function makeGreeter(): callable
{
    $greeting = 'hello';

    return function () use /*|closure_capture*/($greeting) {
        return $greeting;
    };
}
