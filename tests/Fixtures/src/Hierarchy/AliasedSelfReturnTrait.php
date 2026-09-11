<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

trait AliasedSelfReturnTrait
{
    public function fluent(): self
    {
        return $this;
    }
}
