<?php

declare(strict_types=1);

namespace Fixtures\Composition;

/**
 * `insteadof` excludes a method from a trait's whole table, including what it
 * got from a trait it uses; the method name is matched case-insensitively.
 */
class InsteadofReachesNested
{
    use GreetsThroughGreets, GreetsAgain {
        GreetsAgain::HELLO insteadof GreetsThroughGreets;
    }
}
