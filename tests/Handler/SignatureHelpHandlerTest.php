<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Document\DocumentSourceInterface;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\ResolvedCallableInterface;
use Firehed\PhpLsp\Handler\SignatureHelpHandler;
use Firehed\PhpLsp\Resolution\CallContext;
use Firehed\PhpLsp\Resolution\CodeResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SignatureHelpHandler::class)]
class SignatureHelpHandlerTest extends TestCase
{
    use BuildsHandlerRequestsTrait;
    use StubsCollaboratorsTrait;

    private const METHOD = 'textDocument/signatureHelp';
    private const URI = 'file:///test.php';

    public function testSupports(): void
    {
        $handler = new SignatureHelpHandler(
            self::createStub(DocumentSourceInterface::class),
            self::createStub(CodeResolverInterface::class),
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
        $codeResolver = $this->createMock(CodeResolverInterface::class);
        $codeResolver->expects($this->never())->method('getCallContext');

        $handler = new SignatureHelpHandler($documents, $codeResolver);
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
        $codeResolver = $this->createMock(CodeResolverInterface::class);
        $codeResolver->expects($this->never())->method('getCallContext');

        $handler = new SignatureHelpHandler($documents, $codeResolver);
        $result = $handler->handle($this->positionRequest(self::METHOD, self::URI));

        self::assertNull($result, 'an unopened document short-circuits before resolution');
    }

    public function testHandleReturnsNullWhenTheCursorIsNotInACall(): void
    {
        $document = new TextDocument(self::URI, 'php', 1, '<?php ');
        $documents = self::createStub(DocumentSourceInterface::class);
        $documents->method('read')->willReturn($document);
        $codeResolver = $this->createMock(CodeResolverInterface::class);
        $codeResolver
            ->expects($this->once())
            ->method('getCallContext')
            ->with($document, 3, 7)
            ->willReturn(null);

        $handler = new SignatureHelpHandler($documents, $codeResolver);
        $result = $handler->handle($this->positionRequest(self::METHOD, self::URI, line: 3, character: 7));

        self::assertNull($result, 'a cursor outside any call yields no signature help');
    }

    public function testHandleFormatsTheResolvedCallableAsASingleSignature(): void
    {
        $callable = self::createStub(ResolvedCallableInterface::class);
        $callable->method('format')->willReturn('signatureHelpAdd(int $a, int $b): int');
        $callable->method('getDocumentation')->willReturn('Adds two numbers together.');
        $callable->method('getParameters')->willReturn([
            $this->parameter('a', position: 0),
            $this->parameter('b', position: 1),
        ]);
        $context = new CallContext($callable, activeParameterIndex: 1, usedParameterNames: []);

        $handler = new SignatureHelpHandler(
            $this->documentsReturning(new TextDocument(self::URI, 'php', 1, '<?php ')),
            $this->codeResolverReturning($context),
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
            $this->codeResolverReturning($context),
        );
        $result = $handler->handle($this->positionRequest(self::METHOD, self::URI));

        self::assertIsArray($result);
        self::assertArrayNotHasKey(
            'documentation',
            $result['signatures'][0],
            'a callable without documentation must not surface a documentation key',
        );
    }

    private function codeResolverReturning(?CallContext $context): CodeResolverInterface
    {
        $stub = self::createStub(CodeResolverInterface::class);
        $stub->method('getCallContext')->willReturn($context);
        return $stub;
    }

    private function parameter(string $name, int $position): ParameterInfo
    {
        return new ParameterInfo(
            name: $name,
            type: new PrimitiveType('int'),
            hasDefault: false,
            defaultValue: null,
            position: $position,
            isVariadic: false,
            isPassedByReference: false,
        );
    }
}
