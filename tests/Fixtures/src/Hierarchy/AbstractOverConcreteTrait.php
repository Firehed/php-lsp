<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

/**
 * A trait's own method wins over one from a trait it uses, even when its own
 * is abstract.
 */
trait AbstractOverConcreteTrait
{
    use ConcreteSharedTrait;

    abstract public function shared(): string;
}
