<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Capability\SessionCapabilitiesProviderInterface;
use Firehed\PhpLsp\Document\DocumentSourceInterface;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ResolvedSymbolInterface;
use Firehed\PhpLsp\Handler\HoverHandler;
use Firehed\PhpLsp\Protocol\MarkupKind;
use Firehed\PhpLsp\Resolution\CodeResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HoverHandler::class)]
class HoverHandlerTest extends TestCase
{
    use BuildsHandlerRequestsTrait;

    private const METHOD = 'textDocument/hover';
    private const URI = 'file:///test.php';

    public function testSupports(): void
    {
        $handler = $this->handlerFor(MarkupKind::PlainText);

        self::assertTrue(
            $handler->supports('textDocument/hover'),
            'the handler claims its own method',
        );
        self::assertFalse(
            $handler->supports('textDocument/definition'),
            'the handler does not claim any other method',
        );
    }

    public function testHandleReturnsNullWhenPositionParamsAreMalformed(): void
    {
        $documents = $this->createMock(DocumentSourceInterface::class);
        $documents->expects($this->never())->method('read');
        $codeResolver = $this->createMock(CodeResolverInterface::class);
        $codeResolver->expects($this->never())->method('resolveAtPosition');

        $handler = new HoverHandler(
            $documents,
            $codeResolver,
            $this->capabilitiesFor(MarkupKind::PlainText),
        );
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

        $handler = new HoverHandler(
            $documents,
            $codeResolver,
            $this->capabilitiesFor(MarkupKind::PlainText),
        );
        $result = $handler->handle($this->positionRequest(self::METHOD, self::URI));

        self::assertNull($result, 'an unopened document short-circuits before resolution');
    }

    public function testHandleReturnsNullWhenNoSymbolResolves(): void
    {
        $handler = new HoverHandler(
            $this->documentsReturning(new TextDocument(self::URI, 'php', 1, '<?php ')),
            $this->resolverReturning(null),
            $this->capabilitiesFor(MarkupKind::PlainText),
        );

        $result = $handler->handle($this->positionRequest(self::METHOD, self::URI));

        self::assertNull($result, 'a cursor that resolves to nothing has nothing to hover');
    }

    /**
     * @param list<string> $expectedLines
     */
    #[DataProvider('formatCases')]
    public function testHandleFormatsContentsForMarkupKindAndDocumentation(
        MarkupKind $kind,
        ?string $documentation,
        array $expectedLines,
    ): void {
        $symbol = self::createStub(ResolvedSymbolInterface::class);
        $symbol->method('format')->willReturn('User::setName(string $name): void');
        $symbol->method('getDocumentation')->willReturn($documentation);

        $handler = new HoverHandler(
            $this->documentsReturning(new TextDocument(self::URI, 'php', 1, '<?php ')),
            $this->resolverReturning($symbol),
            $this->capabilitiesFor($kind),
        );

        $result = $handler->handle($this->positionRequest(self::METHOD, self::URI));

        self::assertSame(
            [
                'contents' => [
                    'kind' => $kind->value,
                    'value' => implode("\n\n", $expectedLines),
                ],
            ],
            $result,
            'the handler must advertise the negotiated markup kind and lay out the parts under it',
        );
    }

    /**
     * @return iterable<string, array{MarkupKind, ?string, list<string>}>
     *
     * @codeCoverageIgnore
     */
    public static function formatCases(): iterable
    {
        yield 'plaintext, no documentation' => [
            MarkupKind::PlainText,
            null,
            ['User::setName(string $name): void'],
        ];
        yield 'plaintext, with documentation' => [
            MarkupKind::PlainText,
            "Updates the user's display name.",
            [
                "Updates the user's display name.",
                'User::setName(string $name): void',
            ],
        ];
        yield 'markdown, no documentation, fences the signature as PHP' => [
            MarkupKind::Markdown,
            null,
            ["```php\nUser::setName(string \$name): void\n```"],
        ];
        yield 'markdown, with documentation, doc precedes fenced signature' => [
            MarkupKind::Markdown,
            "Updates the user's display name.",
            [
                "Updates the user's display name.",
                "```php\nUser::setName(string \$name): void\n```",
            ],
        ];
    }

    private function handlerFor(MarkupKind $kind): HoverHandler
    {
        return new HoverHandler(
            self::createStub(DocumentSourceInterface::class),
            self::createStub(CodeResolverInterface::class),
            $this->capabilitiesFor($kind),
        );
    }

    private function capabilitiesFor(MarkupKind $kind): SessionCapabilitiesProviderInterface
    {
        $stub = self::createStub(SessionCapabilitiesProviderInterface::class);
        $stub->method('getSessionCapabilities')->willReturn(new SessionCapabilities(hoverMarkupKind: $kind));
        return $stub;
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
