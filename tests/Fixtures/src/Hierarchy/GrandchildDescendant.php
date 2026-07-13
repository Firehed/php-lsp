<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

/**
 * Third level of the class chain: inherits interface, trait and parent members
 * without redeclaring any of them.
 */
class GrandchildDescendant extends ConcreteDescendant
{
    public const GRANDCHILD_CONST = 'grandchild';

    public string $grandchildProperty = 'grandchild';

    public function grandchildMethod(): string
    {
        return 'grandchild';
    }
}
