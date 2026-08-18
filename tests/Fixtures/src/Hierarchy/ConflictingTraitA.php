<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

trait ConflictingTraitA
{
    public function conflictMethod(): string
    {
        return 'A';
    }

    public function onlyInA(): string
    {
        return 'A';
    }
}
