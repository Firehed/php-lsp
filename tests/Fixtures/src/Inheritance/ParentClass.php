<?php

declare(strict_types=1);

namespace Fixtures\Inheritance;

class ParentClass extends Grandparent
{
    public const PARENT_CONST = 'parent';
    protected const PROTECTED_CONST = 'protected';

    public string $parentProperty = 'parent';
    protected int $protectedProperty = 0;
    private bool $privateProperty = false;

    public static string $staticProperty = 'static';

    public function __construct(string $name = '')
    {
    }

    public function parentMethod(): void
    {
    }

    public static function staticMethod(): void
    {
    }

    protected static function protectedStaticMethod(): void
    {
    }

    private static function privateStaticMethod(): void
    {
    }

    protected function protectedMethod(): void
    {
    }

    private function privateMethod(): void
    {
    }
}
