<?php

declare(strict_types=1);

namespace Fixtures\Definition;

use BufferChange\Original;
use BufferChange\Renamed;

final class BufferDeclared
{
    public function original(): Original
    {
        return new Original(); //hover:original
    }

    public function renamed(): Renamed
    {
        return new Renamed(); //hover:renamed
    }
}
