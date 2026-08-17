<?php

namespace Test;

use Fixtures\Completion\MethodAccess;

class MethodAndPropertySameName extends MethodAccess
{
    // Shares its name with the inherited property `$active`: a name identifies a
    // member only together with its kind, and the two rules differ on case.
    public function active(): void
    {
    }
}
