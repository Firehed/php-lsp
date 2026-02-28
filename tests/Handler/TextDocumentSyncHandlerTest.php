<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Protocol\NotificationMessage;
use Firehed\PhpLsp\TypeInference\TypeInferenceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextDocumentSyncHandler::class)]
class TextDocumentSyncHandlerTest extends TestCase
{
    public function testSupports(): void
    {
        $handler = new TextDocumentSyncHandler(new DocumentManager());

        self::assertTrue($handler->supports('textDocument/didOpen'));
        self::assertTrue($handler->supports('textDocument/didChange'));
        self::assertTrue($handler->supports('textDocument/didClose'));
        self::assertFalse($handler->supports('textDocument/hover'));
    }

    public function testDidOpen(): void
    {
        $manager = new DocumentManager();
        $handler = new TextDocumentSyncHandler($manager);

        $notification = NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => [
                'textDocument' => [
                    'uri' => 'file:///test.php',
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => '<?php echo "hello";',
                ],
            ],
        ]);

        $handler->handle($notification);

        $doc = $manager->get('file:///test.php');
        self::assertNotNull($doc);
        self::assertSame('<?php echo "hello";', $doc->getContent());
    }

    public function testDidChange(): void
    {
        $manager = new DocumentManager();
        $handler = new TextDocumentSyncHandler($manager);

        // First open
        $manager->open('file:///test.php', 'php', 1, '<?php echo "v1";');

        // Then change (full sync)
        $notification = NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didChange',
            'params' => [
                'textDocument' => [
                    'uri' => 'file:///test.php',
                    'version' => 2,
                ],
                'contentChanges' => [
                    ['text' => '<?php echo "v2";'],
                ],
            ],
        ]);

        $handler->handle($notification);

        $doc = $manager->get('file:///test.php');
        self::assertNotNull($doc);
        self::assertSame('<?php echo "v2";', $doc->getContent());
        self::assertSame(2, $doc->version);
    }

    public function testDidClose(): void
    {
        $manager = new DocumentManager();
        $handler = new TextDocumentSyncHandler($manager);

        $manager->open('file:///test.php', 'php', 1, '<?php');

        $notification = NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didClose',
            'params' => [
                'textDocument' => [
                    'uri' => 'file:///test.php',
                ],
            ],
        ]);

        $handler->handle($notification);

        self::assertNull($manager->get('file:///test.php'));
    }

    public function testDidChangeInvalidatesTypeInferenceCache(): void
    {
        $manager = new DocumentManager();
        $typeInference = $this->createMock(TypeInferenceInterface::class);
        $handler = new TextDocumentSyncHandler($manager, null, $typeInference);

        $manager->open('file:///test.php', 'php', 1, '<?php echo "v1";');

        $typeInference->expects($this->once())
            ->method('invalidate')
            ->with('file:///test.php');

        $notification = NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didChange',
            'params' => [
                'textDocument' => [
                    'uri' => 'file:///test.php',
                    'version' => 2,
                ],
                'contentChanges' => [
                    ['text' => '<?php echo "v2";'],
                ],
            ],
        ]);

        $handler->handle($notification);
    }

    public function testDidCloseInvalidatesTypeInferenceCache(): void
    {
        $manager = new DocumentManager();
        $typeInference = $this->createMock(TypeInferenceInterface::class);
        $handler = new TextDocumentSyncHandler($manager, null, $typeInference);

        $manager->open('file:///test.php', 'php', 1, '<?php');

        $typeInference->expects($this->once())
            ->method('invalidate')
            ->with('file:///test.php');

        $notification = NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didClose',
            'params' => [
                'textDocument' => [
                    'uri' => 'file:///test.php',
                ],
            ],
        ]);

        $handler->handle($notification);
    }
}
