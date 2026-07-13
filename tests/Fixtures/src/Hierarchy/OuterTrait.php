<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

/**
 * A trait that uses another trait.
 */
trait OuterTrait
{
    use InnerTrait;

    public string $outerProperty = 'outer';

    public function outerMethod(): string
    {
        return 'outer';
    }
}
