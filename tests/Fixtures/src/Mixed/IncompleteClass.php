<?php

declare(strict_types=1);

namespace Fixtures\Mixed;

class IncompleteClass
{
    public function existingMethod(): string
    {
        return 'exists';
    }

    public function methodInProgress(): void
    {
        $this->
    }
}
