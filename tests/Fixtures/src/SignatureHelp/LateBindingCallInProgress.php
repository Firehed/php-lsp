<?php

declare(strict_types=1);

namespace Fixtures\SignatureHelp;

class LateBindingCallInProgress
{
    public static function make(int $count): static
    {
        return new static();
    }

    public function run(): void
    {
        /*|typing*/
    }
}
