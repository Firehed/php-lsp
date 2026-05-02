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

    public function triggerHoverInheritedMethod(): void
    {
        $this->parentMethod(); //hover:inherited_method
    }

    public function triggerHoverInheritedProperty(): void
    {
        echo $this->parentProperty; //hover:inherited_property
    }

    public function triggerHoverGrandparentMethod(): void
    {
        $this->grandparentMethod(); //hover:grandparent_method
    }

    public function triggerHoverGrandparentProperty(): void
    {
        echo $this->grandparentProperty; //hover:grandparent_property
    }

    public function triggerHoverOverriddenMethod(): void
    {
        $this->overriddenMethod(); //hover:overridden_method
    }

    public function triggerHoverSharedProperty(): void
    {
        echo $this->sharedProperty; //hover:shared_property
    }

    public function triggerHoverPrivateMethod(): void
    {
        $this->privateMethod(); //hover:private_method
    }

    public function triggerHoverPrivateProperty(): void
    {
        echo $this->privateProperty; //hover:private_property
    }
}
