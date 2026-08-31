<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Capability\SessionCapabilitiesProvider;
use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\HoverHandler;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\MarkupKind;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\ExpressionResolver;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymbolResolver::class)]
#[CoversClass(ExpressionResolver::class)]
class CallableHoverParityTest extends TestCase
{
    use OpensDocumentsTrait;

    private DocumentManager $documents;
    private ParserService $parser;
    private SymbolResolver $symbolResolver;
    private HoverHandler $handler;
    private TextDocumentSyncHandler $syncHandler;

    protected function setUp(): void
    {
        $this->documents = new DocumentManager();
        $this->parser = new ParserService();

        $knowledge = KnowledgeStack::forProject(
            new ComposerAutoloadMap(),
            __DIR__ . '/../Fixtures/vendor',
            $this->parser,
        );
        $memberResolver = new MemberResolver($knowledge->source);
        $this->symbolResolver = new SymbolResolver(
            $this->parser,
            $knowledge->source,
            $memberResolver,
        );

        $capabilities = self::createStub(SessionCapabilitiesProvider::class);
        $capabilities->method('getSessionCapabilities')
            ->willReturn(new SessionCapabilities(hoverMarkupKind: MarkupKind::PlainText));

        $this->handler = new HoverHandler($this->documents, $this->symbolResolver, $capabilities);
        $this->syncHandler = new TextDocumentSyncHandler($this->documents, $knowledge->sink);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function callableStaticReturnCases(): iterable
    {
        yield 'MethodCall on subclass with static return' => [
            'method_call_static',
            ': Fixtures\\LateBinding\\Sub',
        ];
        yield 'NullsafeMethodCall on subclass with static return' => [
            'nullsafe_method_call_static',
            ': Fixtures\\LateBinding\\Sub',
        ];
        yield 'StaticCall on subclass with static return' => [
            'static_call_static',
            ': Fixtures\\LateBinding\\Sub',
        ];
    }

    /**
     * Late-binding return-type resolution must agree across every callable node
     * kind: an instance, nullsafe, or static call on `Sub` where the method's
     * declared return type is `static` all report the receiver's class in the
     * hover signature. This is the parity the M×N handler×node rewrite forbids
     * regressing.
     */
    #[DataProvider('callableStaticReturnCases')]
    public function testHoverSignatureAgreesOnStaticReturnAcrossCallableKinds(
        string $marker,
        string $expectedReturn,
    ): void {
        $this->openFixture('src/LateBinding/Base.php');
        $this->openFixture('src/LateBinding/Sub.php');
        $cursor = $this->openFixtureAtHoverMarker('src/LateBinding/CallSites.php', $marker);

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString(
            $expectedReturn,
            $result['contents']['value'],
            'the resolved return type must reflect late-static binding on the receiver',
        );
    }

    public function testHoverSignatureResolvesSelfToDeclaringClass(): void
    {
        $this->openFixture('src/LateBinding/Base.php');
        $this->openFixture('src/LateBinding/Sub.php');
        $cursor = $this->openFixtureAtHoverMarker('src/LateBinding/CallSites.php', 'method_call_self');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString(
            ': Fixtures\\LateBinding\\Base',
            $result['contents']['value'],
            'self return type resolves to the declaring class regardless of receiver',
        );
    }

    public function testHoverSignatureResolvesParentToParentClass(): void
    {
        $this->openFixture('src/LateBinding/Base.php');
        $this->openFixture('src/LateBinding/Sub.php');
        $cursor = $this->openFixtureAtHoverMarker('src/LateBinding/CallSites.php', 'method_call_parent');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString(
            ': Fixtures\\LateBinding\\Base',
            $result['contents']['value'],
            'parent return type resolves to the declaring class\'s parent',
        );
    }
}
