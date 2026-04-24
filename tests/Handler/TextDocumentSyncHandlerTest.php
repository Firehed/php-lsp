<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
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
    private DocumentManager $manager;
    private ParserService $parser;
    private DefaultClassInfoFactory $classInfoFactory;
    private DefaultClassRepository $classRepository;
    private TextDocumentSyncHandler $handler;

    protected function setUp(): void
    {
        $this->manager = new DocumentManager();
        $this->parser = new ParserService();
        $this->classInfoFactory = new DefaultClassInfoFactory();
        $locator = self::createStub(ClassLocator::class);
        $this->classRepository = new DefaultClassRepository($this->classInfoFactory, $locator, $this->parser);
        $indexer = new DocumentIndexer($this->parser, new SymbolExtractor(), new SymbolIndex());
        $this->handler = new TextDocumentSyncHandler(
            $this->manager,
            $this->parser,
            $this->classRepository,
            $this->classInfoFactory,
            $indexer,
        );
    }

    public function testSupports(): void
    {
        self::assertTrue($this->handler->supports('textDocument/didOpen'));
        self::assertTrue($this->handler->supports('textDocument/didChange'));
        self::assertTrue($this->handler->supports('textDocument/didClose'));
        self::assertFalse($this->handler->supports('textDocument/hover'));
    }

    public function testDidOpen(): void
    {
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

        $this->handler->handle($notification);

        $doc = $this->manager->get('file:///test.php');
        self::assertNotNull($doc);
        self::assertSame('<?php echo "hello";', $doc->getContent());
    }

    public function testDidChange(): void
    {
        $this->manager->open('file:///test.php', 'php', 1, '<?php echo "v1";');

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

        $this->handler->handle($notification);

        $doc = $this->manager->get('file:///test.php');
        self::assertNotNull($doc);
        self::assertSame('<?php echo "v2";', $doc->getContent());
        self::assertSame(2, $doc->version);
    }

    public function testDidClose(): void
    {
        $this->manager->open('file:///test.php', 'php', 1, '<?php');

        $notification = NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didClose',
            'params' => [
                'textDocument' => [
                    'uri' => 'file:///test.php',
                ],
            ],
        ]);

        $this->handler->handle($notification);

        self::assertNull($this->manager->get('file:///test.php'));
    }

    public function testDidOpenRegistersClasses(): void
    {
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

        $this->handler->handle($notification);

        /** @var class-string $className */
        $className = 'MyTestClass'; // @phpstan-ignore varTag.nativeType
        $classInfo = $this->classRepository->get(new ClassName($className));
        self::assertNotNull($classInfo);
        self::assertSame('MyTestClass', $classInfo->name->shortName());
    }

    public function testDidChangeUpdatesClasses(): void
    {
        // Open with initial class
        $this->manager->open('file:///test.php', 'php', 1, '<?php class OldClass {}');
        $this->classRepository->updateDocument('file:///test.php', [
            $this->classInfoFactory->fromAstNode(
                new \PhpParser\Node\Stmt\Class_('OldClass'),
                'file:///test.php',
            ),
        ]);

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

        $this->handler->handle($notification);

        /** @var class-string $oldClassName */
        $oldClassName = 'OldClass'; // @phpstan-ignore varTag.nativeType
        /** @var class-string $newClassName */
        $newClassName = 'NewClass'; // @phpstan-ignore varTag.nativeType
        self::assertNull($this->classRepository->get(new ClassName($oldClassName)));
        $newClass = $this->classRepository->get(new ClassName($newClassName));
        self::assertNotNull($newClass);
        self::assertSame('NewClass', $newClass->name->shortName());
    }

    public function testDidCloseRemovesClasses(): void
    {
        /** @var class-string $className */
        $className = 'ToBeRemoved'; // @phpstan-ignore varTag.nativeType

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
        $this->handler->handle($openNotification);
        self::assertNotNull($this->classRepository->get(new ClassName($className)));

        $closeNotification = NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didClose',
            'params' => [
                'textDocument' => [
                    'uri' => 'file:///test.php',
                ],
            ],
        ]);
        $this->handler->handle($closeNotification);

        self::assertNull($this->classRepository->get(new ClassName($className)));
    }
}
