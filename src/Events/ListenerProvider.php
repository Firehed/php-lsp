<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Events;

use Closure;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * A PSR-14 listener provider that keys subscriptions by the event's own type
 * string ({@see EventInterface::$type}). Match is exact-string, so lookup is
 * one array read: no runtime kind inspection, no `instanceof` on a computed
 * class name. Subscribers that treat two concrete events the same way register
 * against each type; the tax is two lines rather than one, and it keeps the
 * dispatch path free of the disallowed introspection functions.
 */
final class ListenerProvider implements ListenerProviderInterface
{
    /** @var array<class-string<EventInterface>, list<Closure>> */
    private array $listeners = [];

    /**
     * @template T of EventInterface
     * @param class-string<T> $eventClass
     * @param Closure(T): void $listener
     */
    public function addListener(string $eventClass, Closure $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    /**
     * @return iterable<Closure>
     */
    public function getListenersForEvent(object $event): iterable
    {
        if (!$event instanceof EventInterface) {
            return [];
        }

        yield from $this->listeners[$event->type] ?? [];
    }
}
