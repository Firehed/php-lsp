<?php

declare(strict_types=1);

namespace Fixtures\Namespacing;

use Fixtures\Namespacing\Models\UserRepository as Repo;
use function Fixtures\Namespacing\Models\makeUser;
use const Fixtures\Namespacing\Models\DEFAULT_LIMIT;

class FileWide
{
    public function greet(): string
    {
        return 'Hello from file-wide namespace';
    }
}
