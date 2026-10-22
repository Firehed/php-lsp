<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Document\DocumentSourceInterface;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\ResolvedCallableInterface;
use Firehed\PhpLsp\Handler\SignatureHelpHandler;
use Firehed\PhpLsp\Resolution\CallContext;
use Firehed\PhpLsp\Resolution\Position\CallAtPositionInterface;
use Firehed\PhpLsp\Resolution\ResolvedSymbolPresenter;
use Firehed\PhpLsp\Tests\BuildsSymbolInfoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SignatureHelpHandler::class)]
class SignatureHelpHandlerTest extends TestCase
{
    use BuildsHandlerRequestsTrait;
    use BuildsSymbolInfoTrait;
    use StubsCollaboratorsTrait;

    private const METHOD = 'textDocument/signatureHelp';
    private const URI = 'file:///test.php';

    public function testSupports(): void
    {
        $handler = new SignatureHelpHandler(
            self::createStub(DocumentSourceInterface::class),
            self::createStub(CallAtPositionInterface::class),
            new ResolvedSymbolPresenter(),
        );

        self::assertTrue(
            $handler->supports('textDocument/signatureHelp'),
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
        $calls = $this->createMock(CallAtPositionInterface::class);
        $calls->expects($this->never())->method('callAt');

        $handler = new SignatureHelpHandler($documents, $calls, new ResolvedSymbolPresenter());
        $result = $handler->handle($this->request(self::METHOD, ['textDocument' => 'not-an-object']));

        self::assertNull($result, 'a malformed textDocument shape short-circuits before any resolver call');
    }

    public function testHandleReturnsNullWhenDocumentIsNotOpen(): void
    {
        $documents = $this->createMock(DocumentSourceInterface::class);
        $documents
            ->expects($this->once())
            ->method('read')
            ->with(self::URI)
            ->willReturn(null);
        $calls = $this->createMock(CallAtPositionInterface::class);
        $calls->expects($this->never())->method('callAt');

        $handler = new SignatureHelpHandler($documents, $calls, new ResolvedSymbolPresenter());
        $result = $handler->handle($this->positionRequest(self::METHOD, self::URI));

        self::assertNull($result, 'an unopened document short-circuits before resolution');
    }

    public function testHandleReturnsNullWhenThePositionIsNotInACall(): void
    {
        $document = new TextDocument(self::URI, 'php', 1, "<?php\nfoo();");
        $documents = self::createStub(DocumentSourceInterface::class);
        $documents->method('read')->willReturn($document);
        $calls = $this->createMock(CallAtPositionInterface::class);
        $calls
            ->expects($this->once())
            ->method('callAt')
            ->with($document, new ByteOffset(9))
            ->willReturn(null);

        $handler = new SignatureHelpHandler($documents, $calls, new ResolvedSymbolPresenter());
        $result = $handler->handle($this->positionRequest(self::METHOD, self::URI, line: 1, character: 3));

        self::assertNull($result, 'a position outside any call yields no signature help');
    }

    public function testHandleFormatsTheResolvedCallableAsASingleSignature(): void
    {
        $callable = self::createStub(ResolvedCallableInterface::class);
        $callable->method('format')->willReturn('signatureHelpAdd(int $a, int $b): int');
        $callable->method('getDocumentation')->willReturn('Adds two numbers together.');
        $callable->method('getParameters')->willReturn([
            self::parameterInfo('a', new PrimitiveType('int'), 0),
            self::parameterInfo('b', new PrimitiveType('int'), 1),
        ]);
        $context = new CallContext($callable, activeParameterIndex: 1, usedParameterNames: []);

        $handler = new SignatureHelpHandler(
            $this->documentsReturning(new TextDocument(self::URI, 'php', 1, '<?php ')),
            self::callsReturning($context),
            new ResolvedSymbolPresenter(),
        );
        $result = $handler->handle($this->positionRequest(self::METHOD, self::URI));

        self::assertSame(
            [
                'signatures' => [[
                    'label' => 'signatureHelpAdd(int $a, int $b): int',
                    'parameters' => [['label' => 'int $a'], ['label' => 'int $b']],
                    'documentation' => 'Adds two numbers together.',
                ]],
                'activeSignature' => 0,
                'activeParameter' => 1,
            ],
            $result,
            'the handler emits one signature with the presenter output, parameter labels, and the active index',
        );
    }

    public function testHandleOmitsDocumentationWhenTheCallableHasNone(): void
    {
        $callable = self::createStub(ResolvedCallableInterface::class);
        $callable->method('format')->willReturn('bare(): void');
        $callable->method('getDocumentation')->willReturn(null);
        $callable->method('getParameters')->willReturn([]);
        $context = new CallContext($callable, activeParameterIndex: 0, usedParameterNames: []);

        $handler = new SignatureHelpHandler(
            $this->documentsReturning(new TextDocument(self::URI, 'php', 1, '<?php ')),
            self::callsReturning($context),
            new ResolvedSymbolPresenter(),
        );
        $result = $handler->handle($this->positionRequest(self::METHOD, self::URI));

        self::assertIsArray($result);
        self::assertArrayNotHasKey(
            'documentation',
            $result['signatures'][0],
            'a callable without documentation must not surface a documentation key',
        );
    }

    private static function callsReturning(?CallContext $context): CallAtPositionInterface
    {
        $stub = self::createStub(CallAtPositionInterface::class);
        $stub->method('callAt')->willReturn($context);
        return $stub;
    }
}
