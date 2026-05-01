<?php

declare(strict_types=1);

namespace Fixtures\Inheritance;

class ChildClass extends ParentClass
{
    public const CHILD_CONST = 'child';

    public string $childProperty = 'child';

    public function childMethod(): void
    {
    }

    public function overriddenMethod(): void
    {
        parent::parentMethod();
    }

    public function triggerDirectParentStatic(): void
    {
        ParentClass::/*|direct_parent_static*/
    }

    public function withParentParam(parent $obj): void
    {
        $x = $obj;
    }

    public function triggerGrandparentAccess(): void
    {
        Grandparent::/*|grandparent_access*/
    }
}
