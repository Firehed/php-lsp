<?php

declare(strict_types=1);

namespace Fixtures\Hover;

use Fixtures\Domain\Entity;
use Fixtures\Domain\User;
use Fixtures\Inheritance\ChildClass;

class HoverScenarios extends ChildClass
{
    public function triggerClassHover(): void
    {
        $x = new /*|class_hover*/User("id", "name", "email");
    }

    public function triggerMethodHover(): void
    {
        $user = new User("id", "name", "email");
        $user->/*|method_hover*/setName("new");
    }

    public function triggerPropertyHover(): void
    {
        $user = new User("id", "name", "email");
        echo $user->/*|property_hover*/manager;
    }

    public function triggerStaticMethodHover(): void
    {
        User::/*|static_method_hover*/create("id", "name", "email");
    }

    public function useTypedUser(User $user): void
    {
        $user->/*|typed_param_method*/setName("name");
    }

    public function useAssignedUser(): void
    {
        $user = new User("id", "name", "email");
        $user->/*|assigned_var_method*/setName("name");
    }

    public function triggerNullsafeMethod(): void
    {
        $user = new User("id", "name", "email");
        $user->manager?->/*|nullsafe_method*/setName("name");
    }

    public function triggerNullsafeProperty(): void
    {
        $user = new User("id", "name", "email");
        echo $user->manager?->/*|nullsafe_property*/manager;
    }

    public function useNullsafeTypedUser(?User $user): void
    {
        $user?->/*|nullsafe_typed_param*/setName("name");
    }

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

    public function triggerSharedProperty(): void
    {
        echo $this->/*|shared_property*/sharedProperty;
    }

    public function triggerPrivateMethod(): void
    {
        $this->/*|private_inherited_method*/privateMethod();
    }

    public function triggerPrivateProperty(): void
    {
        echo $this->/*|private_inherited_property*/privateProperty;
    }

    public function triggerTraitMethod(): void
    {
        $user = new User("id", "name", "email");
        $user->/*|trait_method*/markCreated();
    }

    public function useInterface(Entity $entity): void
    {
        $entity->/*|interface_method*/getId();
    }
}
