<?php

declare(strict_types=1);

namespace Fixtures\Resolution;

use Fixtures\Inheritance\ParentClass;

final class ClosureLateBound extends ParentClass
{
    public function closures(): void
    {
        $bySelf = function (self $own): void {
        };
        $byParent = fn (?parent $inherited) => $inherited;
    }
}
