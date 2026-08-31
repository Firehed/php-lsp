<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Knowledge\SymbolSource;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\NotificationMessage;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextDocumentSyncHandler::class)]
class TextDocumentSyncHandlerTest extends TestCase
{
    use LoadsFixturesTrait;

    private DocumentManager $manager;
    private ParserService $parser;
    private SymbolSource $source;
    private TextDocumentSyncHandler $handler;

    protected function setUp(): void
    {
        $this->manager = new DocumentManager();
        $this->parser = new ParserService();
        $knowledge = KnowledgeStack::forProject(
            new ComposerAutoloadMap(),
            dirname(__DIR__) . '/Fixtures/vendor',
            $this->parser,
        );
        $this->source = $knowledge->source;
        $this->handler = new TextDocumentSyncHandler($this->manager, $knowledge->sink);
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

    /**
     * Step 0 acceptance: one parse per handled message. The sync path parsed
     * twice — once here to register the document's classes, then again inside
     * DocumentIndexer — for every keystroke the client sent.
     */
    public function testSyncParsesTheDocumentOnce(): void
    {
        $notification = NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => [
                'textDocument' => [
                    'uri' => 'file:///fixtures/src/Domain/User.php',
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => $this->loadFixture('src/Domain/User.php'),
                ],
            ],
        ]);

        $this->handler->handle($notification);

        self::assertSame(
            1,
            $this->parser->getMetrics()->getParseCount(),
            'registering classes and indexing symbols share one parse',
        );
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
        $classInfo = $this->source->lookupClassLike(new ClassName($className));
        self::assertNotNull($classInfo);
        self::assertSame('MyTestClass', $classInfo->name->shortName());
    }

    public function testDidChangeUpdatesClasses(): void
    {
        // Open with the initial class through the handler, registering it.
        $this->handler->handle(NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => [
                'textDocument' => [
                    'uri' => 'file:///test.php',
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => '<?php class OldClass {}',
                ],
            ],
        ]));

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
        self::assertNull($this->source->lookupClassLike(new ClassName($oldClassName)));
        $newClass = $this->source->lookupClassLike(new ClassName($newClassName));
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
        self::assertNotNull($this->source->lookupClassLike(new ClassName($className)));

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

        self::assertNull($this->source->lookupClassLike(new ClassName($className)));
    }
}
