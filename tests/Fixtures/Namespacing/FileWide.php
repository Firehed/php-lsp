<?php

declare(strict_types=1);

namespace Fixtures\Namespacing;

class FileWide
{
    public function greet(): string
    {
        return 'Hello from file-wide namespace';
    }
}
