<?php

declare(strict_types=1);

namespace Fixtures\TypeInference;

use Fixtures\Inheritance\ParentClass;

class NewKeywords extends ParentClass
{
    public function createSelf(): self
    {
        return new self();
    }

    public static function createStatic(): static
    {
        return new static();
    }

    public function createParent(): ParentClass
    {
        return new parent();
    }
}
