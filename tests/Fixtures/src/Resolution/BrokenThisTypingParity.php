<?php

declare(strict_types=1);

namespace Fixtures\Resolution;

// A truncated class-like scope: the parser recovers `$this` as a top-level
// statement in the namespace, not inside the class-body AST. `$this`'s parent
// chain is therefore detached from any Class_ node, so `EnclosingClassResolver`
// must fall back to text-scanning the document for the enclosing class.
class BrokenThisTypingParity {
    public string $name = '';
}

$this;
$this->/*|broken_member*/;
