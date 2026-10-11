<?php

declare(strict_types=1);

namespace Fixtures\TypeInference;

class NullablePrimitiveParameters
{
    public function limit(?int $count, ?string $label): void
    {
    }
}
