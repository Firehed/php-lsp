<?php

declare(strict_types=1);

namespace Fixtures\Composition;

trait RequiresHelper
{
    abstract public function helper(): string;
}
