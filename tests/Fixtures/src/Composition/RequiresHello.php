<?php

declare(strict_types=1);

namespace Fixtures\Composition;

trait RequiresHello
{
    abstract public function hello(): string;
}
