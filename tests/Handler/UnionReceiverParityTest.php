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
use Firehed\PhpLsp\Handler\DefinitionHandler;
use Firehed\PhpLsp\Handler\HoverHandler;
use Firehed\PhpLsp\Handler\SignatureHelpHandler;
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

/**
 * Every handler that reaches through {@see ExpressionResolver::resolveMember}
 * must find a member on any class in a union or intersection receiver, not
 * only the first. `$x->getName()` where `$x: Entity|Person` and only Person
 * declares `getName()` must answer everywhere completion offers it.
 */
#[CoversClass(ExpressionResolver::class)]
#[CoversClass(SymbolResolver::class)]
class UnionReceiverParityTest extends TestCase
{
    use OpensDocumentsTrait;

    private DocumentManager $documents;
    private HoverHandler $hover;
    private DefinitionHandler $definition;
    private SignatureHelpHandler $signatureHelp;
    private CompletionHandler $completion;
    private TextDocumentSyncHandler $syncHandler;

    protected function setUp(): void
    {
        $this->documents = new DocumentManager();
        $parser = new ParserService();

        $fixturesRoot = __DIR__ . '/../Fixtures';
        $knowledge = KnowledgeStack::forProject(
            ComposerAutoloadMap::fromProjectRoot($fixturesRoot),
            $fixturesRoot . '/vendor',
            $parser,
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
        $this->definition = new DefinitionHandler($this->documents, $symbolResolver);
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

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function unionReceiverCases(): iterable
    {
        yield 'method declared only on second constituent' => [
            'src/Union/UnionReceiver.php',
            'union_person_member',
            'getName',
            'Fixtures\\Domain\\Person',
        ];
        yield 'method declared only on first constituent' => [
            'src/Union/UnionReceiver.php',
            'union_entity_member',
            'getId',
            'Fixtures\\Domain\\Entity',
        ];
    }

    #[DataProvider('unionReceiverCases')]
    public function testHoverResolvesMemberOnEitherUnionConstituent(
        string $fixture,
        string $marker,
        string $memberName,
        string $declaringClass,
    ): void {
        $this->openFixture('src/Domain/Entity.php');
        $this->openFixture('src/Domain/Person.php');
        $cursor = $this->openFixtureAtHoverMarker($fixture, $marker);

        $result = $this->hover->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result, "hover must answer for {$memberName}() on the union");
        self::assertStringContainsString(
            $memberName,
            $result['contents']['value'],
            "hover signature must name {$memberName}",
        );
    }

    #[DataProvider('unionReceiverCases')]
    public function testDefinitionResolvesMemberOnEitherUnionConstituent(
        string $fixture,
        string $marker,
        string $memberName,
        string $declaringClass,
    ): void {
        $entityUri = $this->openFixture('src/Domain/Entity.php');
        $personUri = $this->openFixture('src/Domain/Person.php');
        $cursor = $this->openFixtureAtHoverMarker($fixture, $marker);

        $result = $this->definition->handle($this->definitionRequestAt($cursor));

        self::assertIsArray($result, "definition must answer for {$memberName}() on the union");
        $expected = $declaringClass === 'Fixtures\\Domain\\Person' ? $personUri : $entityUri;
        self::assertSame(
            $expected,
            $result['uri'],
            "definition must land in the class that declares {$memberName}",
        );
    }

    public function testSignatureHelpResolvesMemberDeclaredOnlyOnSecondUnionConstituent(): void
    {
        $this->openFixture('src/Domain/Entity.php');
        $this->openFixture('src/Domain/Person.php');
        $cursor = $this->openFixtureAtCursor('src/Union/UnionReceiver.php', 'union_signature');

        $result = $this->signatureHelp->handle($this->signatureHelpRequestAt($cursor));

        self::assertIsArray($result, 'signature help must answer for a union-receiver method call');
        self::assertNotEmpty($result['signatures'], 'signature help must return at least one signature');
        self::assertStringContainsString(
            'getName',
            $result['signatures'][0]['label'],
            'the resolved signature must be Person::getName',
        );
    }

    public function testCompletionOffersMembersFromEveryUnionConstituent(): void
    {
        $this->openFixture('src/Domain/Entity.php');
        $this->openFixture('src/Domain/Person.php');
        $cursor = $this->openFixtureAtCursor('src/Union/UnionReceiver.php', 'union_completion');

        $result = $this->completion->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('getId', $labels, 'Entity::getId must be offered on the union receiver');
        self::assertContains('getName', $labels, 'Person::getName must be offered on the union receiver');
    }
}
