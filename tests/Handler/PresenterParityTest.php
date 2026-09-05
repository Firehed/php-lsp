<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Capability\SessionCapabilitiesProvider;
use Firehed\PhpLsp\Completion\BuiltinTypeCandidates;
use Firehed\PhpLsp\Completion\KeywordCandidates;
use Firehed\PhpLsp\Completion\MemberCandidates;
use Firehed\PhpLsp\Completion\NamedArgumentCandidates;
use Firehed\PhpLsp\Completion\SymbolCandidates;
use Firehed\PhpLsp\Completion\VariableCandidates;
use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\CompletionHandler;
use Firehed\PhpLsp\Handler\HoverHandler;
use Firehed\PhpLsp\Handler\SignatureHelpHandler;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Protocol\MarkupKind;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\DefaultTextSymbolExtractor;
use Firehed\PhpLsp\Resolution\ResolvedSymbolPresenter;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Hover, signature-help, and completion-detail all read a symbol through
 * {@see ResolvedSymbolPresenter}. This test pins the parity: the docblock
 * description surfaces the same way on all three, and the tag portion is
 * stripped on all three. A future user-facing field added to the presenter
 * (a deprecation notice, say) is one edit that reaches every surface — a
 * regression would break the fixture on one surface but not another.
 */
#[CoversClass(ResolvedSymbolPresenter::class)]
class PresenterParityTest extends TestCase
{
    use OpensDocumentsTrait;

    private const string FIXTURE = 'PresenterParity.php';
    private const string EXPECTED_DESCRIPTION = 'Doubles the input number.';

    private DocumentManager $documents;
    private HoverHandler $hover;
    private SignatureHelpHandler $signatureHelp;
    private CompletionHandler $completion;
    private TextDocumentSyncHandler $syncHandler;

    protected function setUp(): void
    {
        $this->documents = new DocumentManager();
        $production = ProductionSyntaxSource::create();
        $parser = $production->source;

        $fixturesRoot = __DIR__ . '/../Fixtures';
        $knowledge = KnowledgeStack::forProject(
            ComposerAutoloadMap::fromProjectRoot($fixturesRoot),
            $fixturesRoot . '/vendor',
            $parser,
            $production->reader,
            new SymbolIndex(),
            ProductionSyntaxSource::defaultTextSymbolExtractor(),
        );

        $memberResolver = new MemberResolver($knowledge->source);
        $symbolResolver = new SymbolResolver(
            $parser,
            $knowledge->source,
            $memberResolver,
        );

        $capabilities = self::createStub(SessionCapabilitiesProvider::class);
        $capabilities->method('getSessionCapabilities')
            ->willReturn(new SessionCapabilities(hoverMarkupKind: MarkupKind::PlainText));

        $this->hover = new HoverHandler($this->documents, $symbolResolver, $capabilities);
        $this->signatureHelp = new SignatureHelpHandler($this->documents, $symbolResolver);
        $this->completion = new CompletionHandler(
            $this->documents,
            $symbolResolver,
            new SymbolCandidates($knowledge->source, $symbolResolver, $capabilities),
            new KeywordCandidates(),
            new VariableCandidates($symbolResolver),
            new MemberCandidates($symbolResolver, $capabilities),
            new NamedArgumentCandidates(),
            new BuiltinTypeCandidates(),
        );
        $this->syncHandler = new TextDocumentSyncHandler($this->documents, $knowledge->sink);
    }

    public function testHoverSurfaceUsesTheDescription(): void
    {
        $cursor = $this->openFixtureAtHoverMarker(self::FIXTURE, 'presenter_hover');
        $result = $this->hover->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        $body = $result['contents']['value'];
        self::assertStringContainsString(
            self::EXPECTED_DESCRIPTION,
            $body,
            'hover reads documentation through the presenter',
        );
        self::assertStringNotContainsString(
            '@param',
            $body,
            'hover strips docblock tags because the presenter owns extraction',
        );
    }

    public function testSignatureHelpSurfaceUsesTheDescription(): void
    {
        $cursor = $this->openFixtureAtCursor(self::FIXTURE, 'presenter_sig');
        $result = $this->signatureHelp->handle($this->signatureHelpRequestAt($cursor));

        self::assertIsArray($result);
        $doc = $result['signatures'][0]['documentation'] ?? '';
        self::assertSame(
            self::EXPECTED_DESCRIPTION,
            $doc,
            'signature-help documentation is the stripped description',
        );
    }

    public function testCompletionDetailSurfaceUsesTheDescription(): void
    {
        $cursor = $this->openFixtureAtCursor(self::FIXTURE, 'presenter_completion');
        $result = $this->completion->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $item = null;
        foreach ($result['items'] as $candidate) {
            if ($candidate['label'] === 'presenterParityDouble') {
                $item = $candidate;
                break;
            }
        }
        self::assertNotNull($item, 'completion offers the documented function');
        self::assertSame(
            self::EXPECTED_DESCRIPTION,
            $item['documentation'] ?? null,
            'completion detail is the stripped description',
        );
    }
}
