<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Events;

/**
 * Every event dispatched through this system exposes its own type as a string.
 * The listener provider keys subscriptions by that string and matches events by
 * exact type, so lookup is one array read — no `instanceof $var`, no `is_a`, no
 * `get_class`. Subscribers that would otherwise register against an interface
 * (both file-shaped events sharing one drop path) register against each concrete
 * type instead; that is a two-line tax the alternative is not worth paying.
 */
interface EventInterface
{
    public string $type { get; }
}
