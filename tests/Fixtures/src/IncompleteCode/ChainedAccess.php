<?php

declare(strict_types=1);

namespace Fixtures\IncompleteCode;

use Fixtures\Domain\User;

class ChainedAccess
{
    private User $user;

    public function test(): void
    {
        if ($this->user->/*|chained_in_if*/
    }
}

class ChainedToUntyped
{
    /** @var mixed */
    public $untypedProperty;

    public function test(): void
    {
        // Chain to untyped property should fail
        $this->untypedProperty->/*|untyped_chain*/
    }
}

class ChainedToNonExistent
{
    public function test(): void
    {
        // Chain to non-existent property should fail
        $this->nonExistent->/*|nonexistent_chain*/
    }
}

