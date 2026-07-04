<?php

declare(strict_types=1);

namespace Fixtures\IncompleteCode;

use Fixtures\Inheritance\ParentClass;

// Class that only has inherited members, no direct definitions
class InheritedOnlyChild extends ParentClass
{
}

class InheritedOnlyAccess
{
    public function test(InheritedOnlyChild $child): void
    {
        if ($child->/*|inherited_only*/
    }
}
