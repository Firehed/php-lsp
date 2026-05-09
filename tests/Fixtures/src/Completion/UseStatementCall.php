<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Fixtures\Domain\User;

class UseStatementCall
{
    public function testImportedClassNew(): void
    {
        // New on imported class
        new User(/*|imported_new*/
    }
}
