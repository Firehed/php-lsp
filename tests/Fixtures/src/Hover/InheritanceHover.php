<?php

declare(strict_types=1);

namespace Fixtures\Hover;

use Fixtures\Inheritance\ChildClass;

class InheritanceHover extends ChildClass
{
    public function triggerInheritedMethod(): void
    {
        $this->/*|inherited_method*/parentMethod();
    }

    public function triggerInheritedProperty(): void
    {
        echo $this->/*|inherited_property*/parentProperty;
    }

    public function triggerGrandparentMethod(): void
    {
        $this->/*|grandparent_method*/grandparentMethod();
    }

    public function triggerGrandparentProperty(): void
    {
        echo $this->/*|grandparent_property*/grandparentProperty;
    }

    public function triggerOverriddenMethod(): void
    {
        $this->/*|overridden_method*/overriddenMethod();
    }

    public function triggerOverriddenProperty(): void
    {
        echo $this->/*|overridden_property*/sharedProperty;
    }

    public function triggerPrivateMethod(): void
    {
        $this->/*|private_method*/privateMethod();
    }

    public function triggerPrivateProperty(): void
    {
        echo $this->/*|private_property*/privateProperty;
    }
}
