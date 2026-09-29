<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Filesystem;

final readonly class PathStamp
{
    public function __construct(
        public int $modifiedAt,
        public int $size,
    ) {
    }
}
