<?php

declare(strict_types=1);

namespace Fixtures\Inheritance;

class ParentClass extends Grandparent
{
    public const PARENT_CONST = 'parent';
    protected const PROTECTED_CONST = 'protected';

    /** Parent property. */
    public string $parentProperty = 'parent';
    /** Protected parent property. */
    protected int $protectedProperty = 0;
    /** Private parent property. */
    private bool $privateProperty = false;
    /** Shared property from parent. */
    protected string $sharedProperty = 'parent';

    /** Static property documentation. */
    public static string $staticProperty = 'static';

    public function __construct(string $name = '')
    {
    }

    /** Parent method documentation. */
    public function parentMethod(): void
    {
    }

    /** Parent implementation of overridden method. */
    public function overriddenMethod(): void
    {
    }

    /** Static method documentation. */
    public static function staticMethod(): void
    {
    }

    /** Protected static method. */
    protected static function protectedStaticMethod(): void
    {
    }

    /** Private static method. */
    private static function privateStaticMethod(): void
    {
    }

    /** Protected method documentation. */
    protected function protectedMethod(): void
    {
    }

    /** Private parent method. */
    private function privateMethod(): void
    {
    }

    public function triggerHoverStaticProperty(): void
    {
        echo ParentClass::$staticProperty; //hover:staticProperty
    }
}
