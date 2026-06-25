<?php

declare(strict_types=1);

namespace Fixtures\IncompleteCode;

// Parser recovery struggles with severely malformed class structures.
// This class will fail AST parsing but text extraction can still find members.
class VeryBrokenTarget
    public const NAME = 'target';

    public static function create(): self
    {
        return new self();
    }
}

class IncompleteBrokenStatic
{
    public function test(): void
    {
        if (VeryBrokenTarget::/*|broken_static*/
    }
}
