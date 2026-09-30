<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Document\DocumentSourceInterface;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Domain\ResolvedSymbolInterface;
use Firehed\PhpLsp\Handler\DefinitionHandler;
use Firehed\PhpLsp\Resolution\CodeResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefinitionHandler::class)]
class DefinitionHandlerTest extends TestCase
{
    use BuildsHandlerRequestsTrait;

    private const METHOD = 'textDocument/definition';
    private const URI = 'file:///test.php';

    public function testSupports(): void
    {
        $handler = new DefinitionHandler(
            self::createStub(DocumentSourceInterface::class),
            self::createStub(CodeResolverInterface::class),
        );

        self::assertTrue(
            $handler->supports('textDocument/definition'),
            'the handler claims its own method',
        );
        self::assertFalse(
            $handler->supports('textDocument/hover'),
            'the handler does not claim any other method',
        );
    }

    public function testHandleReturnsNullWhenPositionParamsAreMalformed(): void
    {
        $documents = $this->createMock(DocumentSourceInterface::class);
        $documents->expects($this->never())->method('read');
        $codeResolver = $this->createMock(CodeResolverInterface::class);
        $codeResolver->expects($this->never())->method('resolveAtPosition');

        $handler = new DefinitionHandler($documents, $codeResolver);
        $result = $handler->handle(
            $this->request(self::METHOD, ['textDocument' => 'not-an-object']),
        );

        self::assertNull($result, 'a malformed textDocument shape short-circuits before any resolver call');
    }

    public function testHandleReturnsNullWhenDocumentIsNotOpen(): void
    {
        $documents = $this->createMock(DocumentSourceInterface::class);
        $documents->expects($this->once())->method('read')->with(self::URI)->willReturn(null);
        $codeResolver = $this->createMock(CodeResolverInterface::class);
        $codeResolver->expects($this->never())->method('resolveAtPosition');

        $handler = new DefinitionHandler($documents, $codeResolver);
        $result = $handler->handle($this->positionRequest(self::METHOD, self::URI));

        self::assertNull($result, 'an unopened document short-circuits before resolution');
    }

    public function testHandleReturnsNullWhenNoSymbolResolves(): void
    {
        $document = new TextDocument(self::URI, 'php', 1, '<?php ');
        $documents = self::createStub(DocumentSourceInterface::class);
        $documents->method('read')->willReturn($document);
        $codeResolver = $this->createMock(CodeResolverInterface::class);
        $codeResolver
            ->expects($this->once())
            ->method('resolveAtPosition')
            ->with($document, 4, 2)
            ->willReturn(null);

        $handler = new DefinitionHandler($documents, $codeResolver);
        $result = $handler->handle($this->positionRequest(self::METHOD, self::URI, 4, 2));

        self::assertNull($result, 'a cursor that resolves to nothing yields no location');
    }

    public function testHandleReturnsNullWhenTheSymbolHasNoDefinitionLocation(): void
    {
        $symbol = self::createStub(ResolvedSymbolInterface::class);
        $symbol->method('getDefinitionLocation')->willReturn(null);

        $handler = new DefinitionHandler(
            $this->documentsReturning(new TextDocument(self::URI, 'php', 1, '<?php ')),
            $this->resolverReturning($symbol),
        );
        $result = $handler->handle($this->positionRequest(self::METHOD, self::URI));

        self::assertNull(
            $result,
            'a symbol without a definition location has nothing to jump to; the handler must not fabricate one',
        );
    }

    public function testHandleReturnsTheLspLocationOfTheSymbolsDefinition(): void
    {
        $location = new Location(
            uri: 'file:///target.php',
            startLine: 12,
            startCharacter: 4,
            endLine: 12,
            endCharacter: 20,
        );
        $symbol = self::createStub(ResolvedSymbolInterface::class);
        $symbol->method('getDefinitionLocation')->willReturn($location);

        $handler = new DefinitionHandler(
            $this->documentsReturning(new TextDocument(self::URI, 'php', 1, '<?php ')),
            $this->resolverReturning($symbol),
        );
        $result = $handler->handle($this->positionRequest(self::METHOD, self::URI));

        self::assertSame(
            $location->toLspLocation(),
            $result,
            'the handler emits the resolved symbols definition location in LSP wire shape',
        );
    }

    private function documentsReturning(TextDocument $document): DocumentSourceInterface
    {
        $stub = self::createStub(DocumentSourceInterface::class);
        $stub->method('read')->willReturn($document);
        return $stub;
    }

    private function resolverReturning(?ResolvedSymbolInterface $symbol): CodeResolverInterface
    {
        $stub = self::createStub(CodeResolverInterface::class);
        $stub->method('resolveAtPosition')->willReturn($symbol);
        return $stub;
    }
}
