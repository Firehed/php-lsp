<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\InvalidatableInterface;

/**
 * A single reachable object for the sink's invalidation fan-out, so the ordered
 * membership lives in one place and adding or dropping a cached on-disk holder
 * is one edit here (CLAUDE.md Caching/Invalidation).
 *
 * The autoload-map reader runs first: a `vendor/composer/` change drops its
 * cached map before the disk backends read it, so their identity check against
 * the map they last built from fires and rebuilds the derived index. The maps
 * cache decorator's per-path accounting cannot describe that blast radius, so
 * it is flushed wholesale instead.
 */
final readonly class CompositeInvalidatable implements InvalidatableInterface
{
    public function __construct(
        private ComposerAutoloadMapReader $mapReader,
        private CachingSymbolSource $mapsDecorator,
        private ComposerMapBackend $mapsBackend,
        private AutoloadFilesBackend $filesBackend,
    ) {
    }

    public function invalidate(string $uri): void
    {
        if ($this->mapReader->isComposerAutoloadFile($uri)) {
            $this->mapReader->invalidate($uri);
            $this->mapsDecorator->flush();
        } else {
            $this->mapsDecorator->invalidate($uri);
        }
        $this->mapsBackend->invalidate($uri);
        $this->filesBackend->invalidate($uri);
    }
}
