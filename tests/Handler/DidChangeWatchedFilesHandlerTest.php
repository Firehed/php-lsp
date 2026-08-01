<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Handler\DidChangeWatchedFilesHandler;
use Firehed\PhpLsp\Knowledge\SymbolSink;
use Firehed\PhpLsp\Protocol\NotificationMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DidChangeWatchedFilesHandler::class)]
class DidChangeWatchedFilesHandlerTest extends TestCase
{
    public function testSupportsOnlyTheWatchedFilesMethod(): void
    {
        $handler = new DidChangeWatchedFilesHandler(self::createStub(SymbolSink::class));

        self::assertTrue($handler->supports('workspace/didChangeWatchedFiles'));
        self::assertFalse($handler->supports('textDocument/didChange'));
    }

    public function testInvalidatesEveryChangedFileRegardlessOfChangeType(): void
    {
        $sink = $this->createMock(SymbolSink::class);
        // Created, changed, and deleted alike drop the cached entry (RFC 1 §5.2).
        $matcher = $this->exactly(3);
        $sink->expects($matcher)
            ->method('invalidate')
            ->willReturnCallback(function (string $uri) use ($matcher): void {
                $expected = [
                    'file:///workspace/src/Created.php',
                    'file:///workspace/src/Changed.php',
                    'file:///workspace/src/Deleted.php',
                ];
                self::assertSame(
                    $expected[$matcher->numberOfInvocations() - 1],
                    $uri,
                    'each reported change must be invalidated in order',
                );
            });

        $handler = new DidChangeWatchedFilesHandler($sink);
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

    public function testAnEmptyChangeSetInvalidatesNothing(): void
    {
        $sink = $this->createMock(SymbolSink::class);
        $sink->expects($this->never())->method('invalidate');

        $handler = new DidChangeWatchedFilesHandler($sink);
        $handler->handle(NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'workspace/didChangeWatchedFiles',
            'params' => ['changes' => []],
        ]));
    }
}
