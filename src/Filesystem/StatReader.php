<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Filesystem;

final class StatReader
{
    public function stamp(string $path): ?PathStamp
    {
        // PHP remembers stat results for the life of the process.
        clearstatcache(true, $path);
        $stat = @stat($path);

        return $stat === false ? null : new PathStamp($stat['mtime'], $stat['size']);
    }
}
