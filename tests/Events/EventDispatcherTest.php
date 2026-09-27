<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Events;

use Firehed\PhpLsp\Events\EventDispatcher;
use Firehed\PhpLsp\Events\ListenerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\StoppableEventInterface;

#[CoversClass(EventDispatcher::class)]
final class EventDispatcherTest extends TestCase
{
    public function testDispatchReturnsTheSameEventObject(): void
    {
        $dispatcher = new EventDispatcher(new ListenerProvider());
        $event = new SampleEvent();

        self::assertSame($event, $dispatcher->dispatch($event), 'PSR-14 dispatch must return the given event');
    }

    public function testEveryListenerFiresForAMatchingEvent(): void
    {
        $provider = new ListenerProvider();
        $order = [];
        $provider->addListener(SampleEvent::class, static function () use (&$order): void {
            $order[] = 'first';
        });
        $provider->addListener(SampleEvent::class, static function () use (&$order): void {
            $order[] = 'second';
        });
        $dispatcher = new EventDispatcher($provider);

        $dispatcher->dispatch(new SampleEvent());

        self::assertSame(['first', 'second'], $order, 'each registered listener must run in registration order');
    }

    public function testListenerPublishingAFurtherEventReachesTransitiveSubscribers(): void
    {
        // Synchronous, same-tick nesting: this is the ordering the map reader relies
        // on when it turns a watched-file change into an autoload-map regeneration.
        $provider = new ListenerProvider();
        $dispatcher = new EventDispatcher($provider);
        $reached = false;
        $provider->addListener(SampleEvent::class, static function () use ($dispatcher): void {
            $dispatcher->dispatch(new SampleImplementation());
        });
        $provider->addListener(SampleInterface::class, static function () use (&$reached): void {
            $reached = true;
        });

        $dispatcher->dispatch(new SampleEvent());

        self::assertTrue($reached, 'a subscriber to a nested event must see it before the outer dispatch returns');
    }

    public function testAStoppedEventDoesNotReachLaterListeners(): void
    {
        $provider = new ListenerProvider();
        $order = [];
        $provider->addListener(StoppableSample::class, static function (StoppableSample $event) use (&$order): void {
            $order[] = 'first';
            $event->stop();
        });
        $provider->addListener(StoppableSample::class, static function () use (&$order): void {
            $order[] = 'second';
        });
        $dispatcher = new EventDispatcher($provider);

        $dispatcher->dispatch(new StoppableSample());

        self::assertSame(['first'], $order, 'a listener that stops propagation must prevent later listeners from firing');
    }
}
