<?php

declare(strict_types=1);

// The last-good version of Fixtures\IncompleteCode\VeryBroken, opened before the
// broken variant so DocumentSymbolSink registers the class. When the broken content
// arrives it parses to no declarations and the sink preserves this registration,
// so completion on $this-> still offers these members (RFC 1 §5.3).
namespace Fixtures\IncompleteCode;

class VeryBroken
{
    private string $name;

    public function getName(): string
    {
        return $this->name;
    }
}
