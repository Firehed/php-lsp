<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

trait ConflictingTraitB
{
    public function conflictMethod(): string
    {
        return 'B';
    }

    public function onlyInB(): string
    {
        return 'B';
    }
}
