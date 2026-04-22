<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Domain\ClassName;
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

    public function testDidOpenRegistersClasses(): void
    {
        $manager = new DocumentManager();
        $parser = new ParserService();
        $classInfoFactory = new DefaultClassInfoFactory();
        $locator = self::createStub(ClassLocator::class);
        $classRepository = new DefaultClassRepository($classInfoFactory, $locator, $parser);

        $handler = new TextDocumentSyncHandler($manager, $parser, $classRepository, $classInfoFactory);

        $notification = NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => [
                'textDocument' => [
                    'uri' => 'file:///test.php',
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => '<?php class MyTestClass {}',
                ],
            ],
        ]);

        $handler->handle($notification);

        /** @var class-string $className */
        $className = 'MyTestClass'; // @phpstan-ignore varTag.nativeType
        $classInfo = $classRepository->get(new ClassName($className));
        self::assertNotNull($classInfo);
        self::assertSame('MyTestClass', $classInfo->name->shortName());
    }

    public function testDidChangeUpdatesClasses(): void
    {
        $manager = new DocumentManager();
        $parser = new ParserService();
        $classInfoFactory = new DefaultClassInfoFactory();
        $locator = self::createStub(ClassLocator::class);
        $classRepository = new DefaultClassRepository($classInfoFactory, $locator, $parser);

        $handler = new TextDocumentSyncHandler($manager, $parser, $classRepository, $classInfoFactory);

        // Open with initial class
        $manager->open('file:///test.php', 'php', 1, '<?php class OldClass {}');
        $classRepository->updateDocument('file:///test.php', [
            $classInfoFactory->fromAstNode(
                new \PhpParser\Node\Stmt\Class_('OldClass'),
                'file:///test.php',
            ),
        ]);

        // Change to new class
        $notification = NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didChange',
            'params' => [
                'textDocument' => [
                    'uri' => 'file:///test.php',
                    'version' => 2,
                ],
                'contentChanges' => [
                    ['text' => '<?php class NewClass {}'],
                ],
            ],
        ]);

        $handler->handle($notification);

        /** @var class-string $oldClassName */
        $oldClassName = 'OldClass'; // @phpstan-ignore varTag.nativeType
        /** @var class-string $newClassName */
        $newClassName = 'NewClass'; // @phpstan-ignore varTag.nativeType
        self::assertNull($classRepository->get(new ClassName($oldClassName)));
        $newClass = $classRepository->get(new ClassName($newClassName));
        self::assertNotNull($newClass);
        self::assertSame('NewClass', $newClass->name->shortName());
    }

    public function testDidCloseRemovesClasses(): void
    {
        $manager = new DocumentManager();
        $parser = new ParserService();
        $classInfoFactory = new DefaultClassInfoFactory();
        $locator = self::createStub(ClassLocator::class);
        $classRepository = new DefaultClassRepository($classInfoFactory, $locator, $parser);

        $handler = new TextDocumentSyncHandler($manager, $parser, $classRepository, $classInfoFactory);

        /** @var class-string $className */
        $className = 'ToBeRemoved'; // @phpstan-ignore varTag.nativeType

        // Open with a class
        $openNotification = NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => [
                'textDocument' => [
                    'uri' => 'file:///test.php',
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => '<?php class ToBeRemoved {}',
                ],
            ],
        ]);
        $handler->handle($openNotification);
        self::assertNotNull($classRepository->get(new ClassName($className)));

        // Close
        $closeNotification = NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didClose',
            'params' => [
                'textDocument' => [
                    'uri' => 'file:///test.php',
                ],
            ],
        ]);
        $handler->handle($closeNotification);

        self::assertNull($classRepository->get(new ClassName($className)));
    }
}
