<?php

declare(strict_types=1);

namespace Fixtures\Inheritance;

/**
 * Calls members of a class it is unrelated to.
 */
class Outsider
{
    public function triggerHoverPrivateMethod(ParentClass $parent): void
    {
        $parent->privateMethod(); //hover:private_from_outside
    }

    public function triggerHoverProtectedMethod(ParentClass $parent): void
    {
        $parent->protectedMethod(); //hover:protected_from_outside
    }

    public function triggerHoverPublicMethod(ParentClass $parent): void
    {
        $parent->parentMethod(); //hover:public_from_outside
    }

    public function triggerHoverProtectedConstant(): string
    {
        return ParentClass::PROTECTED_CONST; //hover:protected_constant_from_outside
    }
}
