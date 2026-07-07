<?php

namespace Test;

use Fixtures\Inheritance\ParentClass;

class InheritedChild extends ParentClass
{
    public function overriddenMethod(): void
    {
    }

    public function childOwn(): void
    {
    }
}
