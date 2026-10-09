<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class ArgumentSlots
{
    public function target(string $name, int $count = 0): void
    {
    }

    public function slots(): void
    {
        $this->target(/*|positional*/'a');
        $this->target(name: /*|named_value*/'a');
        $this->target(name: 'a', /*|next_argument*/count: 1);
    }
}
