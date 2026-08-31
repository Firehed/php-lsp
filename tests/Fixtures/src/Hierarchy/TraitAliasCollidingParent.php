<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

class TraitAliasCollidingParent
{
    public function inheritedMethod(): string
    {
        return 'parent';
    }
}
