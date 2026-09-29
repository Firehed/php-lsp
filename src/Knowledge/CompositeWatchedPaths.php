<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Watch\WatchedPaths;
use Firehed\PhpLsp\Watch\WatchedPathsSourceInterface;

final readonly class CompositeWatchedPaths implements WatchedPathsSourceInterface
{
    public function __construct(
        private ComposerAutoloadMapReader $mapReader,
        private ComposerMapBackend $mapsBackend,
        private AutoloadFilesBackend $filesBackend,
    ) {
    }

    public function watchedPaths(): WatchedPaths
    {
        return $this->mapReader->watchedPaths()
            ->with($this->mapsBackend->watchedPaths())
            ->with($this->filesBackend->watchedPaths());
    }
}
