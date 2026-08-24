<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Capability\SessionCapabilitiesProvider;
use Firehed\PhpLsp\Completion\BuiltinTypeCandidates;
use Firehed\PhpLsp\Completion\ClassCandidates;
use Firehed\PhpLsp\Completion\FunctionCandidates;
use Firehed\PhpLsp\Completion\KeywordCandidates;
use Firehed\PhpLsp\Completion\MemberCandidates;
use Firehed\PhpLsp\Completion\NamedArgumentCandidates;
use Firehed\PhpLsp\Completion\NamespaceCandidates;
use Firehed\PhpLsp\Completion\VariableCandidates;
use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\CompletionHandler;
use Firehed\PhpLsp\Handler\DefinitionHandler;
use Firehed\PhpLsp\Handler\DocumentFeatureHandler;
use Firehed\PhpLsp\Handler\HoverHandler;
use Firehed\PhpLsp\Handler\SignatureHelpHandler;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Knowledge\DeclarationScanner;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\Repository\DefaultFunctionRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\TypeInference\BasicTypeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Feature-matrix grid: every fixture scenario against every feature handler.
 *
 * Rows are fixture scenarios (a fixture file and a cursor marker). Columns are
 * the feature handlers, derived from all {@see DocumentFeatureHandler}
 * implementations found in src/Handler/. Each cell either asserts the handler
 * answers (returns a non-null, non-empty result) or is registered not-applicable
 * with a named blocker. An unregistered cell that does not answer fails, and a
 * registration on a cell that now answers fails.
 *
 * @phpstan-type CursorPosition array{uri: string, line: int, character: int}
 */
#[CoversClass(DefinitionHandler::class)]
#[CoversClass(HoverHandler::class)]
#[CoversClass(SignatureHelpHandler::class)]
#[CoversClass(CompletionHandler::class)]
final class FeatureMatrixTest extends TestCase
{
    use OpensDocumentsTrait;

    private const string UNOWNED_BLOCKER = '/^(#\d+|(RFC 1|Plan 0002) §\d+(\.\d+)*)$/u';

    /**
     * Cells the current stack cannot answer. Keyed `<fixture>|<marker>|<handler>`.
     *
     * @var array<string, string>
     */
    private const array NOT_APPLICABLE = [
    ];

    /**
     * Each row is [fixture path relative to tests/Fixtures/, hover marker name].
     *
     * @var list<array{string, string}>
     */
    private const array SCENARIOS = [
        ['src/Domain/User.php', 'setName'],
        ['src/Domain/User.php', 'create'],
        ['src/Domain/User.php', 'markCreated'],
    ];

    /** @var array<string, DocumentFeatureHandler> */
    private array $handlers;

    private TextDocumentSyncHandler $syncHandler;

    protected function setUp(): void
    {
        $documents = new DocumentManager();
        $parser = new ParserService();

        $fixturesRoot = __DIR__ . '/../Fixtures';
        $knowledge = KnowledgeStack::forProject(
            ComposerAutoloadMap::fromProjectRoot($fixturesRoot),
            $fixturesRoot . '/vendor',
            $parser,
        );

        $memberResolver = new MemberResolver($knowledge->source);
        $functionRepo = new DefaultFunctionRepository(new DeclarationScanner());
        $typeResolver = new BasicTypeResolver($memberResolver, $functionRepo);

        $symbolResolver = new SymbolResolver(
            $parser,
            $knowledge->source,
            $memberResolver,
            $typeResolver,
            $functionRepo,
            new DeclarationScanner(),
        );

        $capabilities = self::createStub(SessionCapabilitiesProvider::class);
        $capabilities->method('getSessionCapabilities')
            ->willReturn(new SessionCapabilities());

        $this->handlers = [
            DefinitionHandler::class => new DefinitionHandler($documents, $symbolResolver),
            HoverHandler::class => new HoverHandler($documents, $symbolResolver, $capabilities),
            SignatureHelpHandler::class => new SignatureHelpHandler($documents, $symbolResolver),
            CompletionHandler::class => new CompletionHandler(
                $documents,
                $symbolResolver,
                new ClassCandidates($knowledge->source, $symbolResolver, $capabilities),
                new NamespaceCandidates($knowledge->source, $symbolResolver, $capabilities),
                new FunctionCandidates($symbolResolver, $capabilities),
                new KeywordCandidates(),
                new VariableCandidates($symbolResolver),
                new MemberCandidates($symbolResolver, $capabilities),
                new NamedArgumentCandidates(),
                new BuiltinTypeCandidates(),
            ),
        ];

        $this->syncHandler = new TextDocumentSyncHandler($documents, $knowledge->sink);
    }

    public function testEveryCellAnswersOrNamesItsBlocker(): void
    {
        ['unregistered' => $unregistered, 'stale' => $stale] = $this->evaluate(self::NOT_APPLICABLE);

        self::assertSame(
            [],
            $unregistered,
            'every scenario × handler cell must answer or be registered not-applicable with a named blocker',
        );
        self::assertSame(
            [],
            $stale,
            'a cell that now answers must lose its not-applicable registration',
        );
    }

    public function testAnUnregisteredCellIsReported(): void
    {
        ['unregistered' => $unregistered] = $this->evaluate([]);

        $registered = array_keys(self::NOT_APPLICABLE);
        sort($registered);
        sort($unregistered);

        self::assertSame(
            $registered,
            $unregistered,
            'the cells that cannot be answered must be exactly the ones registered',
        );
    }

    public function testARegistrationThatNoLongerBlocksIsReported(): void
    {
        $scenario = self::SCENARIOS[0];
        $handlerName = $this->handlerName(array_values($this->handlers)[0]);
        $answering = "{$scenario[0]}|{$scenario[1]}|{$handlerName}";
        ['stale' => $stale] = $this->evaluate([$answering => 'a blocker that no longer applies']);

        self::assertContains(
            $answering . ' (registered against a blocker that no longer applies)',
            $stale,
            'a registration on a cell that answers must be reported as stale',
        );
    }

    public function testEveryRegistrationNamesALiveBlocker(): void
    {
        self::assertSame(
            [],
            self::danglingBlockers(self::NOT_APPLICABLE),
            'a not-applicable cell must name a step, an issue, or an RFC section',
        );
    }

    public function testABlockerNamingNoSliceIsReported(): void
    {
        self::assertSame(
            ['x|y|z names S9.99'],
            self::danglingBlockers(['x|y|z' => 'S9.99']),
            'a blocker matching no registry row, issue, or section must be reported',
        );
    }

    public function testEveryFeatureHandlerHasAGridColumn(): void
    {
        $implementations = self::discoverImplementations();
        $inGrid = array_keys($this->handlers);

        sort($implementations);
        sort($inGrid);

        self::assertSame(
            $implementations,
            $inGrid,
            'every DocumentFeatureHandler in src/Handler/ must be a column in the grid',
        );
    }

    /**
     * @param array<string, string> $notApplicable
     * @return array{unregistered: list<string>, stale: list<string>}
     */
    private function evaluate(array $notApplicable): array
    {
        $unregistered = [];
        $stale = [];

        foreach (self::SCENARIOS as [$fixture, $marker]) {
            $cursor = $this->openFixtureAtHoverMarker($fixture, $marker);

            foreach ($this->handlers as $handler) {
                $cell = "{$fixture}|{$marker}|{$this->handlerName($handler)}";
                $answered = $this->answers($handler, $cursor);

                if (!$answered && !array_key_exists($cell, $notApplicable)) {
                    $unregistered[] = $cell;
                }
                if ($answered && array_key_exists($cell, $notApplicable)) {
                    $stale[] = $cell . ' (registered against ' . $notApplicable[$cell] . ')';
                }
            }
        }

        return ['unregistered' => $unregistered, 'stale' => $stale];
    }

    /**
     * @param CursorPosition $cursor
     */
    private function answers(DocumentFeatureHandler $handler, array $cursor): bool
    {
        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $handler->method(),
            'params' => [
                'textDocument' => ['uri' => $cursor['uri']],
                'position' => ['line' => $cursor['line'], 'character' => $cursor['character']],
            ],
        ]);

        $result = $handler->handle($request);

        if ($result === null) {
            return false;
        }

        if (is_array($result) && array_key_exists('items', $result)) {
            return $result['items'] !== [];
        }

        return true;
    }

    private function handlerName(DocumentFeatureHandler $handler): string
    {
        $parts = explode('\\', $handler::class);
        return end($parts);
    }

    /**
     * @return list<class-string<DocumentFeatureHandler>>
     */
    private static function discoverImplementations(): array
    {
        $dir = new \DirectoryIterator(dirname(__DIR__, 2) . '/src/Handler');
        $implementations = [];
        foreach ($dir as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            /** @var class-string */
            $class = 'Firehed\\PhpLsp\\Handler\\' . $file->getBasename('.php');
            if (class_exists($class) && is_a($class, DocumentFeatureHandler::class, true)) {
                $implementations[] = $class;
            }
        }
        sort($implementations);
        return $implementations;
    }

    /**
     * @param array<string, string> $notApplicable
     * @return list<string>
     */
    private static function danglingBlockers(array $notApplicable): array
    {
        $slices = self::sliceIds();
        $dangling = [];

        foreach ($notApplicable as $cell => $blocker) {
            foreach (explode(', ', $blocker) as $named) {
                if (in_array($named, $slices, true) || preg_match(self::UNOWNED_BLOCKER, $named) === 1) {
                    continue;
                }
                $dangling[] = "{$cell} names {$named}";
            }
        }

        return $dangling;
    }

    /** @return list<string> */
    private static function sliceIds(): array
    {
        $manifest = file_get_contents(dirname(__DIR__, 2) . '/docs/architecture/build-manifest.md');
        self::assertNotFalse($manifest, 'the slice registry must be readable');

        preg_match_all('/^- \[[ x]\] \*\*([a-z0-9-]+)\*\*/m', $manifest, $matches);
        self::assertNotEmpty($matches[1], 'the slice list must be parseable');

        return $matches[1];
    }
}
