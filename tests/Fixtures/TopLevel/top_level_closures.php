<?php

declare(strict_types=1);

// Code at file level is a scope: an arrow function there captures from the
// file's own bindings, as one in a function captures from the function's
// (PHP manual, Arrow Functions).
$outer = new \DateTime();
$arrow = fn () => $outer; //jtd:top_arrow_capture outer

// A top-level long closure's `use ($closureOuter)` binds the name, and the
// binding takes the type of the file-level variable it captures
// (PHP manual, Anonymous functions).
$closureOuter = new \DateTime();
$closure = function () use ($closureOuter) {
    return $closureOuter; //jtd:top_closure_use_capture closureOuter
};
