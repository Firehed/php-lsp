<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Events;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * PSR-14 synchronous dispatcher. Listeners run in registration order for the
 * event's type; a listener that publishes a further event (the autoload map
 * reader turning a file event into a regeneration event) dispatches it in the
 * same tick, so subscribers to that further event see it before control returns
 * to the outer publisher.
 */
final readonly class EventDispatcher implements EventDispatcherInterface
{
    public function __construct(private ListenerProviderInterface $provider)
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
