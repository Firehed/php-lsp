<?php

declare(strict_types=1);

namespace Fixtures\Inheritance;

class GlobalParent extends \RuntimeException implements \Countable
{
    public function count(): int
    {
        return 0;
    }

    public function wrap(?\Throwable $previous): void
    {
    }
}
