<?php

declare(strict_types=1);

namespace App\Consumer;

use App\Model\User;

class Service
{
    public function make(): void
    {
        new User/*|imported_prefix*/
    }
}
