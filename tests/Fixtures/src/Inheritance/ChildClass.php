<?php

declare(strict_types=1);

namespace Fixtures\Inheritance;

class ChildClass extends ParentClass
{
    public const CHILD_CONST = 'child';

    /** Child property. */
    public string $childProperty = 'child';
    /** Child override of shared property. */
    protected string $sharedProperty = 'child';

    /** Child method documentation. */
    public function childMethod(): void
    {
    }

    /** Child implementation of overridden method. */
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
