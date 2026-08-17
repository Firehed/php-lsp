<?php

namespace Test;

use Fixtures\Inheritance\ParentClass;

// PHP matches method names case-insensitively, so this overrides the parent's
// `overriddenMethod` despite the different spelling.
class CaseVariedOverrideChild extends ParentClass
{
    public function OVERRIDDENMETHOD(): void
    {
    }
}
