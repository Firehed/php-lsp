<?php

declare(strict_types=1);

namespace Fixtures\TypeInference;

class WrittenParameterTypes extends NullablePrimitiveParameters
{
    public function take(INT $count, ?String $label, Iterable $items, self $same, parent $base): void
    {
    }
}
