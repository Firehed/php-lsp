<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\ComposerAutoloadMap;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Events\WatchedFileChangedEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Owns the {@see ComposerAutoloadMap} for the project: reads it on first use and
 * re-reads it when any file under `vendor/composer/` changes on disk. On a
 * re-read the reader publishes {@see AutoloadMapRegeneratedEvent} with the fresh
 * map, so subscribers receive the value they need to rebuild derived state from
 * without polling the reader (RFC 1 §5.2, §5.3).
 */
final class ComposerAutoloadMapReader
{
    private ?ComposerAutoloadMap $map = null;

    private readonly string $composerDir;

    public function __construct(
        private readonly string $projectRoot,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
        $this->composerDir = ComposerAutoloadMap::composerDirFor($projectRoot) . '/';
    }

    /**
     * A reader that returns a pre-built map, for tests that hand-build the
     * autoload contents rather than pointing at a real project on disk. A
     * vendor/composer/ event would still fall through to
     * {@see ComposerAutoloadMap::fromProjectRoot()} against the empty root and
     * produce an empty map, which is the correct answer for a project with no
     * vendor directory.
     */
    public static function fromMap(ComposerAutoloadMap $map, EventDispatcherInterface $dispatcher): self
    {
        $reader = new self('', $dispatcher);
        $reader->map = $map;

        return $reader;
    }

    public function current(): ComposerAutoloadMap
    {
        return $this->map ??= ComposerAutoloadMap::fromProjectRoot($this->projectRoot);
    }

    public function onWatchedFileChanged(WatchedFileChangedEvent $event): void
    {
        if (!str_starts_with(FileUri::toPath($event->uri), $this->composerDir)) {
            return;
        }

        $this->map = ComposerAutoloadMap::fromProjectRoot($this->projectRoot);
        $this->dispatcher->dispatch(new AutoloadMapRegeneratedEvent($this->map));
    }
}
