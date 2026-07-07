<?php

declare(strict_types=1);

namespace Fixtures\IncompleteCode;

class SingleIncompleteSigHelp
{
    public function getName(): string
    {
        return '';
    }

    public function test(): void
    {
        $this->getName(/*|sig_this_call*/
    }
}
