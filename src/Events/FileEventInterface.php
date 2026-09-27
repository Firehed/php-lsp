<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Events;

/**
 * The shared shape of the two editor-sourced file events: a watched file on disk
 * changed, or a buffer that had authority over one closed (RFC 1 §5.2, §5.3).
 * A listener that treats both events the same way types its parameter on this
 * interface; the provider still keys subscriptions by each concrete type, so a
 * subscriber registers against both {@see WatchedFileChangedEvent} and
 * {@see OpenDocumentClosedEvent} with the same callable.
 */
interface FileEventInterface extends EventInterface
{
    public string $uri { get; }
}
