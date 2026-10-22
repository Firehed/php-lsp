<?php

declare(strict_types=1);

namespace Fixtures\Inheritance;

/**
 * Extends the same parent as ChildClass, so the two are siblings.
 */
class SiblingClass extends ParentClass
{
    public function triggerHoverProtectedFromSibling(ChildClass $child): void
    {
        $child->protectedMethod(); //hover:parent_protected_from_sibling
    }

    public function triggerHoverChildProtectedFromSibling(ChildClass $child): void
    {
        $child->childProtectedMethod(); //hover:child_protected_from_sibling
    }
}
