<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Events;

/**
 * The client reported that a file it watches for the server was created, changed
 * or deleted on disk. The change type is not carried because every subscriber
 * reacts to all three the same way — the entry is re-derived on the next query.
 */
final readonly class WatchedFileChangedEvent implements FileEventInterface
{
    public string $type;

    public function __construct(public string $uri)
    {
        $this->type = self::class;
    }
}
