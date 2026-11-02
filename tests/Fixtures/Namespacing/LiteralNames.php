<?php

declare(strict_types=1);

namespace Fixtures\Namespacing\Shadowed {
    const BAR = 5;
}

namespace Fixtures\Namespacing\LiteralNames {
    use const Fixtures\Namespacing\Shadowed\BAR as true;

    $aliased = true;
    $aliased; //jtd:aliased aliased
    $otherCase = TRUE;
    $otherCase; //jtd:other_case otherCase
    $qualified = Sub\false;
    $qualified; //jtd:qualified qualified
}
