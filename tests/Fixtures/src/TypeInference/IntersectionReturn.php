<?php

declare(strict_types=1);

namespace Fixtures\TypeInference;

use ArrayIterator;
use Countable;
use Iterator;

class IntersectionReturn
{
    public static function getIterableCounter(): Iterator&Countable
    {
        return new ArrayIterator([]);
    }
}
