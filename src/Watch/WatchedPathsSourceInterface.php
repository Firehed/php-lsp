<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Watch;

interface WatchedPathsSourceInterface
{
    /**
     * The paths whose change on disk would make what is held here stale, as of
     * now. The answer changes as what is held changes.
     */
    public function watchedPaths(): WatchedPaths;
}
