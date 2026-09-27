<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Events;

use Firehed\PhpLsp\Events\ListenerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(ListenerProvider::class)]
final class ListenerProviderTest extends TestCase
{
    public function testRegisteredListenersReceiveMatchingEventTypes(): void
    {
        $provider = new ListenerProvider();
        $seen = [];
        $provider->addListener(SampleEvent::class, static function (object $event) use (&$seen): void {
            $seen[] = $event;
        });

        $listeners = iterator_to_array($provider->getListenersForEvent(new SampleEvent()), false);

        self::assertCount(1, $listeners, 'the registered listener must be returned for a matching event');
    }

    public function testNonMatchingEventTypesReceiveNoListeners(): void
    {
        $provider = new ListenerProvider();
        $provider->addListener(SampleEvent::class, static fn(object $event): null => null);

        $listeners = iterator_to_array($provider->getListenersForEvent(new stdClass()), false);

        self::assertSame([], $listeners, 'a listener bound to another class must not fire for an unrelated event');
    }

    public function testInterfaceRegistrationMatchesEveryImplementingConcreteEvent(): void
    {
        $provider = new ListenerProvider();
        $provider->addListener(SampleInterface::class, static fn(object $event): null => null);

        $listeners = iterator_to_array($provider->getListenersForEvent(new SampleImplementation()), false);

        self::assertCount(
            1,
            $listeners,
            'a listener bound to an interface must fire for every concrete event implementing it',
        );
    }

    public function testMultipleListenersFireInRegistrationOrder(): void
    {
        $provider = new ListenerProvider();
        $order = [];
        $provider->addListener(SampleEvent::class, static function () use (&$order): void {
            $order[] = 'first';
        });
        $provider->addListener(SampleEvent::class, static function () use (&$order): void {
            $order[] = 'second';
        });

        foreach ($provider->getListenersForEvent(new SampleEvent()) as $listener) {
            $listener(new SampleEvent());
        }

        self::assertSame(['first', 'second'], $order, 'listeners must fire in the order they were registered');
    }
}
