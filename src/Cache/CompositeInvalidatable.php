<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Cache;

/**
 * The composite over {@see InvalidatableInterface} (CLAUDE.md Caching/Invalidation):
 * one path from the editor's change event to every cached on-disk holder for that
 * path. The sink holds one invalidatable so the fan-out order lives in one place.
 */
final readonly class CompositeInvalidatable implements InvalidatableInterface
{
    /**
     * @param list<InvalidatableInterface> $invalidatables
     */
    public function __construct(
        private array $invalidatables,
    ) {
    }

    public function invalidate(string $uri): void
    {
        foreach ($this->invalidatables as $invalidatable) {
            $invalidatable->invalidate($uri);
        }
    }
}
