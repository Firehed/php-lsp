<?php

declare(strict_types=1);

namespace Fixtures\Composition;

trait RequiresShared
{
    abstract public function shared(): string;
}
