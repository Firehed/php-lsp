<?php

declare(strict_types=1);

namespace Fixtures\Inheritance;

class ParentClass extends Grandparent
{
    public const PARENT_CONST = 'parent';
    protected const PROTECTED_CONST = 'protected';

    public string $parentProperty = 'parent';
    protected int $protectedProperty = 0;
    /** @phpstan-ignore property.onlyWritten */
    private bool $privateProperty = false;

    public static string $staticProperty = 'static';

    /** @phpstan-ignore constructor.unusedParameter */
    public function __construct(string $name = '')
    {
    }

    public function parentMethod(): void
    {
    }

    public static function staticMethod(): void
    {
    }

    protected function protectedMethod(): void
    {
    }

    /** @phpstan-ignore method.unused */
    private function privateMethod(): void
    {
    }
}
