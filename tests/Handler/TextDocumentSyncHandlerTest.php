<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Cache\InvalidatableInterface;
use Firehed\PhpLsp\Document\DocumentManagerInterface;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Knowledge\SymbolSinkInterface;
use Firehed\PhpLsp\Protocol\NotificationMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextDocumentSyncHandler::class)]
class TextDocumentSyncHandlerTest extends TestCase
{
    private const URI = 'file:///test.php';

    public function testSupports(): void
    {
        $handler = new TextDocumentSyncHandler(
            self::createStub(DocumentManagerInterface::class),
            self::createStub(SymbolSinkInterface::class),
            self::createStub(InvalidatableInterface::class),
        );

        self::assertTrue($handler->supports('textDocument/didOpen'), 'didOpen is handled');
        self::assertTrue($handler->supports('textDocument/didChange'), 'didChange is handled');
        self::assertTrue($handler->supports('textDocument/didClose'), 'didClose is handled');
        self::assertFalse(
            $handler->supports('textDocument/hover'),
            'the handler does not claim any other method',
        );
    }

    public function testHandleDidOpenRegistersWithTheManagerThenForwardsTheDocumentToTheSink(): void
    {
        $document = new TextDocument(self::URI, 'php', 1, '<?php echo "hello";');

        $documents = $this->createMock(DocumentManagerInterface::class);
        $documents
            ->expects($this->once())
            ->method('open')
            ->with(self::URI, 'php', 1, '<?php echo "hello";');
        $documents
            ->expects($this->once())
            ->method('get')
            ->with(self::URI)
            ->willReturn($document);
        $documents->expects($this->never())->method('update');
        $documents->expects($this->never())->method('close');

        $symbols = $this->createMock(SymbolSinkInterface::class);
        $symbols->expects($this->once())->method('openDocument')->with($document);
        $symbols->expects($this->never())->method('updateDocument');
        $symbols->expects($this->never())->method('closeDocument');

        $invalidator = $this->createMock(InvalidatableInterface::class);
        $invalidator->expects($this->never())->method('invalidate');

        $handler = new TextDocumentSyncHandler($documents, $symbols, $invalidator);
        $handler->handle(NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => [
                'textDocument' => [
                    'uri' => self::URI,
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => '<?php echo "hello";',
                ],
            ],
        ]));
    }

    public function testHandleDidChangeAppliesTheLastContentChangeThroughTheManagerAndSink(): void
    {
        $document = new TextDocument(self::URI, 'php', 2, '<?php echo "v2";');

        $documents = $this->createMock(DocumentManagerInterface::class);
        $documents
            ->expects($this->once())
            ->method('update')
            ->with(self::URI, '<?php echo "v2";', 2);
        $documents
            ->expects($this->once())
            ->method('get')
            ->with(self::URI)
            ->willReturn($document);
        $documents->expects($this->never())->method('open');
        $documents->expects($this->never())->method('close');

        $symbols = $this->createMock(SymbolSinkInterface::class);
        $symbols->expects($this->once())->method('updateDocument')->with($document);
        $symbols->expects($this->never())->method('openDocument');
        $symbols->expects($this->never())->method('closeDocument');

        $invalidator = $this->createMock(InvalidatableInterface::class);
        $invalidator->expects($this->never())->method('invalidate');

        $handler = new TextDocumentSyncHandler($documents, $symbols, $invalidator);
        $handler->handle(NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didChange',
            'params' => [
                'textDocument' => ['uri' => self::URI, 'version' => 2],
                // Only the last change's text is applied (full-sync; LSP §Document Synchronization).
                'contentChanges' => [
                    ['text' => '<?php echo "v1-skipped";'],
                    ['text' => '<?php echo "v2";'],
                ],
            ],
        ]));
    }

    public function testHandleDidChangeIsANoOpWhenContentChangesIsEmpty(): void
    {
        $documents = $this->createMock(DocumentManagerInterface::class);
        $documents->expects($this->never())->method('update');
        $documents->expects($this->never())->method('get');

        $symbols = $this->createMock(SymbolSinkInterface::class);
        $symbols->expects($this->never())->method('updateDocument');

        $invalidator = $this->createMock(InvalidatableInterface::class);
        $invalidator->expects($this->never())->method('invalidate');

        $handler = new TextDocumentSyncHandler($documents, $symbols, $invalidator);
        $handler->handle(NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didChange',
            'params' => [
                'textDocument' => ['uri' => self::URI, 'version' => 2],
                'contentChanges' => [],
            ],
        ]));
    }

    public function testHandleDidCloseInvalidatesAndClosesAcrossAllThreeCollaborators(): void
    {
        $documents = $this->createMock(DocumentManagerInterface::class);
        $documents->expects($this->once())->method('close')->with(self::URI);
        $documents->expects($this->never())->method('open');
        $documents->expects($this->never())->method('update');

        $symbols = $this->createMock(SymbolSinkInterface::class);
        $symbols->expects($this->once())->method('closeDocument')->with(self::URI);
        $symbols->expects($this->never())->method('openDocument');
        $symbols->expects($this->never())->method('updateDocument');

        // The invalidator drop is what makes the next on-disk query re-read disk
        // rather than the pre-edit cached value (RFC 1 §5.3). Covered here at the
        // handler boundary; the fan-out through composite invalidators lives in
        // the integration test.
        $invalidator = $this->createMock(InvalidatableInterface::class);
        $invalidator->expects($this->once())->method('invalidate')->with(self::URI);

        $handler = new TextDocumentSyncHandler($documents, $symbols, $invalidator);
        $handler->handle(NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didClose',
            'params' => [
                'textDocument' => ['uri' => self::URI],
            ],
        ]));
    }
}
