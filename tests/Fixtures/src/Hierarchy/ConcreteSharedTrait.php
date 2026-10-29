<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

trait ConcreteSharedTrait
{
    public function shared(): string
    {
        return 'trait';
    }
}
