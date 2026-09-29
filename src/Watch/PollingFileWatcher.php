<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Watch;

use Closure;
use Firehed\PhpLsp\BeforeMessageInterface;
use Firehed\PhpLsp\Cache\InvalidatableInterface;
use Firehed\PhpLsp\Capability\InitializedListenerInterface;
use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Filesystem\PhpDirectoryReader;
use Firehed\PhpLsp\Filesystem\StatReader;

/**
 * Stands in for `workspace/didChangeWatchedFiles` with a client that cannot
 * send it. [LSP] makes that notification the way a server learns of changes on
 * disk, and makes supporting it optional for the client; with a client that
 * does not, nothing else tells the server a file changed.
 *
 * It reports what the client would have: one event per file created, deleted,
 * or changed, through the same {@see InvalidatableInterface} the notification's
 * handler calls. It does nothing for a client that declared support.
 */
final class PollingFileWatcher implements BeforeMessageInterface, InitializedListenerInterface
{
    private bool $standingIn = false;

    /** @var array<string, Snapshot> File -> how it last looked */
    private array $files = [];

    /** @var array<string, array<string, Snapshot>> Root -> directory at or beneath it -> how it last looked */
    private array $roots = [];

    /**
     * @param Closure(): int $now Unix time
     */
    public function __construct(
        private readonly WatchedPathsSourceInterface $watched,
        private readonly InvalidatableInterface $invalidator,
        private readonly PhpDirectoryReader $directories,
        private readonly StatReader $stat,
        private readonly Closure $now,
    ) {
    }

    public function beforeMessage(): void
    {
        if (!$this->standingIn) {
            return;
        }

        $paths = $this->watched->watchedPaths();
        $now = ($this->now)();
        $changed = [
            ...$this->changedAmong($paths->files, $now),
            ...$this->changedUnder($paths->roots, $now),
        ];

        foreach (array_unique($changed) as $path) {
            $this->invalidator->invalidate(FileUri::fromPath($path));
        }
    }

    public function onInitialized(SessionCapabilities $capabilities): void
    {
        $this->standingIn = !$capabilities->watchedFilesDynamicRegistration;
    }

    /**
     * @param list<string> $files
     * @return list<string>
     */
    private function changedAmong(array $files, int $now): array
    {
        $changed = [];
        $snapshots = [];
        foreach ($files as $file) {
            $stamp = $this->stat->stamp($file);
            if (array_key_exists($file, $this->files) && $this->files[$file]->mayDifferFrom($stamp)) {
                $changed[] = $file;
            }
            $snapshots[$file] = new Snapshot($stamp, $now);
        }
        $this->files = $snapshots;

        return $changed;
    }

    /**
     * @param list<string> $roots
     * @return list<string>
     */
    private function changedUnder(array $roots, int $now): array
    {
        $this->roots = array_intersect_key($this->roots, array_flip($roots));

        $changed = [];
        foreach ($roots as $root) {
            if (!array_key_exists($root, $this->roots)) {
                $this->roots[$root] = $this->snapshotsUnder($root, $now);
                continue;
            }
            foreach ($this->roots[$root] as $directory => $before) {
                $changed = [...$changed, ...$this->changedIn($root, $directory, $before, $now)];
            }
        }

        return $changed;
    }

    /**
     * @return list<string>
     */
    private function changedIn(string $root, string $directory, Snapshot $before, int $now): array
    {
        $stamp = $this->stat->stamp($directory);
        if (!$before->mayDifferFrom($stamp)) {
            return [];
        }

        $listing = $this->directories->read($directory);
        if ($listing === null) {
            // The root stays watched so its return is seen; anything beneath it
            // is found again through its parent.
            if ($directory === $root) {
                $this->roots[$root][$directory] = new Snapshot(null, $now);
            } else {
                unset($this->roots[$root][$directory]);
            }

            return $before->files;
        }

        $this->roots[$root][$directory] = new Snapshot($stamp, $now, $listing->files);
        $changed = [
            ...array_diff($listing->files, $before->files),
            ...array_diff($before->files, $listing->files),
        ];

        foreach ($listing->directories as $child) {
            if (array_key_exists($child, $this->roots[$root])) {
                continue;
            }
            foreach ($this->snapshotsUnder($child, $now) as $path => $snapshot) {
                $this->roots[$root][$path] = $snapshot;
                $changed = [...$changed, ...$snapshot->files];
            }
        }

        return $changed;
    }

    /**
     * @return array<string, Snapshot> The directory and every directory beneath it
     */
    private function snapshotsUnder(string $directory, int $now): array
    {
        $snapshots = [$directory => new Snapshot($this->stat->stamp($directory), $now)];
        foreach ($this->directories->walk($directory) as $listing) {
            $snapshots[$listing->path] = new Snapshot($this->stat->stamp($listing->path), $now, $listing->files);
        }

        return $snapshots;
    }
}
