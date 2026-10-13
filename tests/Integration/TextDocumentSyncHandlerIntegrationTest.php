<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Integration;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ComposerAutoloadMap;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Protocol\NotificationMessage;
use Firehed\PhpLsp\Tests\BuildsKnowledgeStackTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextDocumentSyncHandler::class)]
class TextDocumentSyncHandlerIntegrationTest extends TestCase
{
    use BuildsKnowledgeStackTrait;

    private SymbolSourceInterface $source;
    private TextDocumentSyncHandler $handler;

    protected function setUp(): void
    {
        $production = ProductionSyntaxSource::create();
        $knowledge = $this->knowledgeStackForMap(new ComposerAutoloadMap(), $production);
        $this->source = $knowledge->source;
        $this->handler = new TextDocumentSyncHandler(new DocumentManager(), $knowledge->sink, $knowledge->invalidator);
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
        $classInfo = $this->source->lookupClassLike(ClasslikeName::fromFullyQualified($className));
        self::assertNotNull($classInfo);
        self::assertSame('MyTestClass', $classInfo->name->qualifiedName->shortName);
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

        /** @var class-string $oldClasslikeName */
        $oldClasslikeName = 'OldClass'; // @phpstan-ignore varTag.nativeType
        /** @var class-string $newClasslikeName */
        $newClasslikeName = 'NewClass'; // @phpstan-ignore varTag.nativeType
        self::assertNull($this->source->lookupClassLike(ClasslikeName::fromFullyQualified($oldClasslikeName)));
        $newClass = $this->source->lookupClassLike(ClasslikeName::fromFullyQualified($newClasslikeName));
        self::assertNotNull($newClass);
        self::assertSame('NewClass', $newClass->name->qualifiedName->shortName);
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
        self::assertNotNull($this->source->lookupClassLike(ClasslikeName::fromFullyQualified($className)));

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

        self::assertNull($this->source->lookupClassLike(ClasslikeName::fromFullyQualified($className)));
    }
}
