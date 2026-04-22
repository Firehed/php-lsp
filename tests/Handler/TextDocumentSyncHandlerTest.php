<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\NotificationMessage;
use Firehed\PhpLsp\Repository\ClassLocator;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextDocumentSyncHandler::class)]
class TextDocumentSyncHandlerTest extends TestCase
{
    private function createHandler(DocumentManager $manager): TextDocumentSyncHandler
    {
        $parser = new ParserService();
        $classInfoFactory = new DefaultClassInfoFactory();
        $locator = self::createStub(ClassLocator::class);
        $classRepository = new DefaultClassRepository($classInfoFactory, $locator, $parser);
        return new TextDocumentSyncHandler($manager, $parser, $classRepository, $classInfoFactory);
    }

    public function testSupports(): void
    {
        $handler = $this->createHandler(new DocumentManager());

        self::assertTrue($handler->supports('textDocument/didOpen'));
        self::assertTrue($handler->supports('textDocument/didChange'));
        self::assertTrue($handler->supports('textDocument/didClose'));
        self::assertFalse($handler->supports('textDocument/hover'));
    }

    public function testDidOpen(): void
    {
        $manager = new DocumentManager();
        $handler = $this->createHandler($manager);

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
        $handler = $this->createHandler($manager);

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
        $handler = $this->createHandler($manager);

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
}
