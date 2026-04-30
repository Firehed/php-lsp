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
}
