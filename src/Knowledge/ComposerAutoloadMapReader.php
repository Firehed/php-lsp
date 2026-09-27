<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\InvalidatableInterface;
use Firehed\PhpLsp\Domain\ComposerAutoloadMap;
use Firehed\PhpLsp\Domain\FileUri;

/**
 * Owns the {@see ComposerAutoloadMap} for the project: reads it on first use and
 * re-reads it when any file under `vendor/composer/` changes on disk. The one
 * invalidatable for that path — the {@see AutoloadFilesBackend} and
 * {@see ComposerMapBackend} hold this reader instead of a map snapshot, so a
 * `composer install` that regenerates the autoload files reaches both backends
 * on the next query rather than being trapped in a copy they made at boot
 * (RFC 1 §5.2, §5.3).
 *
 * Each read of {@see current()} returns the same instance until an invalidation
 * drops it; the backends compare that instance against the one they last built
 * their derived indexes from and rebuild when it changes.
 */
final class ComposerAutoloadMapReader implements InvalidatableInterface
{
    private ?ComposerAutoloadMap $map = null;

    private readonly string $composerDir;

    public function __construct(private readonly string $projectRoot)
    {
        $this->composerDir = ComposerAutoloadMap::composerDirFor($projectRoot) . '/';
    }

    /**
     * A reader that returns a pre-built map, for tests that hand-build the
     * autoload contents rather than pointing at a real project on disk. An
     * invalidation from vendor/composer would still fall through to
     * {@see ComposerAutoloadMap::fromProjectRoot()} against the empty root and
     * produce an empty map, which is the correct answer for a project with no
     * vendor directory.
     */
    public static function fromMap(ComposerAutoloadMap $map): self
    {
        $reader = new self('');
        $reader->map = $map;

        return $reader;
    }

    public function current(): ComposerAutoloadMap
    {
        return $this->map ??= ComposerAutoloadMap::fromProjectRoot($this->projectRoot);
    }

    public function invalidate(string $uri): void
    {
        if (str_starts_with(FileUri::toPath($uri), $this->composerDir)) {
            $this->map = null;
        }
    }
}
