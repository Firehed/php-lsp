<?php

declare(strict_types=1);

namespace Fixtures\Composition;

/**
 * A class's own method wins over an alias of the same name.
 */
class OwnMethodBeatsAlias
{
    use Greets {
        hello as greet;
    }

    public function greet(): string
    {
        return 'own';
    }
}
