<?php

declare(strict_types=1);

// A top-level arrow function has no enclosing function scope, so an implicit
// capture from the file-level bindings resolves to no variable — variable JTD
// returns null rather than the file-level assignment.
$outer = 1;
$arrow = fn () => $outer; //jtd:top_arrow_capture outer

// A top-level long closure's `use ($closureOuter)` binds a name, but the
// binding's type derives from a scope that no enclosing function-like defines,
// so hover on the capture reports the name alone rather than the outer type.
$closureOuter = 2;
$closure = function () use ($closureOuter) {
    return $closureOuter; //jtd:top_closure_use_capture closureOuter
};
