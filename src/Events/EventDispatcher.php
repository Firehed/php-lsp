<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Events;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * PSR-14 synchronous dispatcher. Listeners run in registration order for the
 * event's type; a listener that publishes a further event (the autoload map
 * reader turning a file event into a regeneration event) dispatches it in the
 * same tick, so subscribers to that further event see it before control returns
 * to the outer publisher.
 *
 * Typed on {@see ListenerProvider} rather than PSR-14's `ListenerProviderInterface`
 * because the provider's `getListenersForEvent()` on the interface returns
 * `iterable` (no value type), so an interface-typed field forces a widening
 * every call site would then have to unwiden. The concrete class refines that
 * to `iterable<Closure>`, and this dispatcher is the one place the composition
 * root wires it.
 */
final readonly class EventDispatcher implements EventDispatcherInterface
{
    public function __construct(private ListenerProvider $provider)
    {
    }

    public function dispatch(object $event): object
    {
        foreach ($this->provider->getListenersForEvent($event) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }
            $listener($event);
        }

        return $event;
    }
}
