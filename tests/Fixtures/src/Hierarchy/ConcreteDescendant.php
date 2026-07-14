<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

/**
 * Reaches an interface only through its parent class, and satisfies that
 * interface's members here.
 */
class ConcreteDescendant extends AbstractImplementor
{
    public const CONCRETE_CONST = 'concrete';

    public string $concreteProperty = 'concrete';

    public function baseMethod(): string
    {
        return 'base';
    }

    public function middleMethod(): string
    {
        return 'middle';
    }
}
