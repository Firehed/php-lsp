<?php

declare(strict_types=1);

namespace Fixtures\Resolution;

// A truncated class-like scope: the parser recovers `$this` as a top-level
// statement in the namespace, not inside the class-body AST. `$this`'s parent
// chain is therefore detached from any Class_ node, so `ExpressionResolver`
// reads the enclosing class from the node's position via `Scope::atOffset`.
class BrokenThisTypingParity {
    public string $name = '';
}

$this;
$this->/*|broken_member*/;
