<?php

declare(strict_types=1);

namespace Fixtures\Composition;

class AliasingParent
{
    use Greets {
        hello as greet;
    }
}
