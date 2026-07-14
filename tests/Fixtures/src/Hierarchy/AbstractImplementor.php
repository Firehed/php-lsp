<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

/**
 * Implements an interface without redeclaring its members, and pulls in a
 * trait that itself uses another trait.
 */
abstract class AbstractImplementor implements MiddleInterface
{
    use OuterTrait;

    public const ABSTRACT_CONST = 'abstract';

    public string $abstractProperty = 'abstract';

    public function abstractClassMethod(): string
    {
        return 'abstract';
    }
}
