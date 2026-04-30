<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class InheritanceChild extends \Exception
{
    private string $ownProperty = '';

    public function ownMethod(): void
    {
    }

    public function triggerThisInherited(): void
    {
        $this->/*|this_inherited*/
    }
}

class ParentForSelf
{
    public static string $inheritedProperty = 'value';
    public const INHERITED_CONST = 'const';

    public static function inheritedMethod(): void
    {
    }
}

class ChildForSelf extends ParentForSelf
{
    public static string $ownProperty = 'child';

    public static function ownMethod(): void
    {
        self::/*|self_inherited*/
    }
}

class GrandParent
{
    public static function grandparentPublic(): void
    {
    }

    protected static function grandparentProtected(): void
    {
    }
}

class MiddleParent extends GrandParent
{
}

class GrandChild extends MiddleParent
{
    public function triggerDeepInheritance(): void
    {
        GrandParent::/*|deep_inheritance*/
    }
}

class ParentWithConstructor
{
    public function __construct(string $name)
    {
    }

    protected function greet(): string
    {
        return 'Hello';
    }
}

class ChildWithConstructor extends ParentWithConstructor
{
    public function __construct(string $name)
    {
        parent::/*|parent_access*/
    }
}

class ParentWithMethods
{
    public function __construct()
    {
    }

    protected function greet(): string
    {
        return 'Hello';
    }

    protected function goodbye(): string
    {
        return 'Bye';
    }
}

class ChildWithPrefix extends ParentWithMethods
{
    public function test(): void
    {
        parent::gr/*|parent_prefix*/
    }
}

class NoParent
{
    public function test(): void
    {
        parent::/*|parent_no_parent*/
    }
}
