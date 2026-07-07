<?php

declare(strict_types=1);

namespace Fixtures\IncompleteCode;

// Parser recovery struggles with severely malformed class structures.
// This class will fail AST parsing but text extraction can still find members.
class VeryBrokenTarget
    public const NAME = 'target';
    private const SECRET = 'hidden';

    public string $publicProp = 'visible';
    private int $privateProp = 42;

    public static function create(): self
    {
        return new self();
    }

    private static function privateHelper(): void
    {
    }

    public function instanceMethod(): void
    {
    }
}

class IncompleteBrokenStatic
{
    public function test(): void
    {
        if (VeryBrokenTarget::/*|broken_static*/
    }
}

// Test instance member access on broken class from within
class BrokenInstanceAccess
    public string $name = 'test';

    public function test(): void
    {
        $this->/*|broken_instance*/
    }
}
