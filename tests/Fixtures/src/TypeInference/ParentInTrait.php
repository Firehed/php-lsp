<?php

declare(strict_types=1);

namespace Fixtures\TypeInference;

trait ParentInTrait
{
    public function createFromTrait(): mixed
    {
        return new parent();
    }
}
