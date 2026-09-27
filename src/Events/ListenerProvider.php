<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Events;

use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * A PSR-14 listener provider that matches events by class hierarchy. A listener
 * registered against an interface receives every concrete event that implements
 * it; the wiring names one subscription per (event type, listener) pair and never
 * hard-codes the fan-out ordering across subscribers.
 */
final class ListenerProvider implements ListenerProviderInterface
{
    /** @var array<class-string, list<callable(object): void>> */
    private array $listeners = [];

    /**
     * @param class-string $eventClass
     * @param callable(object): void $listener
     */
    public function addListener(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    public function getListenersForEvent(object $event): iterable
    {
        foreach ($this->listeners as $class => $listeners) {
            if ($event instanceof $class) {
                yield from $listeners;
            }
        }
    }
}
