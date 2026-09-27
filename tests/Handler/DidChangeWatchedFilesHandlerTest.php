<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Events\WatchedFileChangedEvent;
use Firehed\PhpLsp\Handler\DidChangeWatchedFilesHandler;
use Firehed\PhpLsp\Protocol\NotificationMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

#[CoversClass(DidChangeWatchedFilesHandler::class)]
class DidChangeWatchedFilesHandlerTest extends TestCase
{
    public function testSupportsOnlyTheWatchedFilesMethod(): void
    {
        $handler = new DidChangeWatchedFilesHandler(self::createStub(EventDispatcherInterface::class));

        self::assertTrue($handler->supports('workspace/didChangeWatchedFiles'));
        self::assertFalse($handler->supports('textDocument/didChange'));
    }

    public function testEveryChangedFilePublishesAnEventRegardlessOfChangeType(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        // Created, changed, and deleted alike publish a WatchedFileChangedEvent (RFC 1 §5.2).
        $matcher = $this->exactly(3);
        $dispatcher->expects($matcher)
            ->method('dispatch')
            ->willReturnCallback(function (object $event) use ($matcher): object {
                $expected = [
                    'file:///workspace/src/Created.php',
                    'file:///workspace/src/Changed.php',
                    'file:///workspace/src/Deleted.php',
                ];
                self::assertInstanceOf(WatchedFileChangedEvent::class, $event);
                self::assertSame(
                    $expected[$matcher->numberOfInvocations() - 1],
                    $event->uri,
                    'each reported change must be published in order',
                );

                return $event;
            });

        $handler = new DidChangeWatchedFilesHandler($dispatcher);
        $result = $handler->handle(NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'workspace/didChangeWatchedFiles',
            'params' => [
                'changes' => [
                    ['uri' => 'file:///workspace/src/Created.php', 'type' => 1],
                    ['uri' => 'file:///workspace/src/Changed.php', 'type' => 2],
                    ['uri' => 'file:///workspace/src/Deleted.php', 'type' => 3],
                ],
            ],
        ]));

        self::assertNull($result, 'a notification handler returns nothing to send');
    }

    public function testAnEmptyChangeSetPublishesNothing(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $handler = new DidChangeWatchedFilesHandler($dispatcher);
        $handler->handle(NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'workspace/didChangeWatchedFiles',
            'params' => ['changes' => []],
        ]));
    }
}
