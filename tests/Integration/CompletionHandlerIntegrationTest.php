<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Integration;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Capability\SessionCapabilitiesProviderInterface;
use Firehed\PhpLsp\Completion\BuiltinTypeCandidates;
use Firehed\PhpLsp\Completion\CompletionItemFactory;
use Firehed\PhpLsp\Completion\CompletionItemKind;
use Firehed\PhpLsp\Completion\CompletionRequest;
use Firehed\PhpLsp\Completion\CompositeCompletionSource;
use Firehed\PhpLsp\Completion\InsertTextFormat;
use Firehed\PhpLsp\Completion\KeywordCandidates;
use Firehed\PhpLsp\Completion\MemberCandidates;
use Firehed\PhpLsp\Completion\NamedArgumentCandidates;
use Firehed\PhpLsp\Completion\SymbolCandidates;
use Firehed\PhpLsp\Completion\VariableCandidates;
use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceContents;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Handler\CompletionHandler;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Parser\SyntaxSource\MemoizingSyntaxSource;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\ExpressionResolver;
use Firehed\PhpLsp\Resolution\ResolvedTypeOnly;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\Resolution\TypeSource\NativeTypeSource;
use Firehed\PhpLsp\Tests\BuildsKnowledgeStackTrait;
use Firehed\PhpLsp\Tests\Completion\WiresCompletionSourceTrait;
use Firehed\PhpLsp\Tests\Handler\OpensDocumentsTrait;
use Firehed\PhpLsp\Tests\Parser\CountingSyntaxSource;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type CompletionItem from CompletionItemFactory
 */
#[CoversClass(CompletionHandler::class)]
#[CoversClass(BuiltinTypeCandidates::class)]
#[CoversClass(CompletionItemFactory::class)]
#[CoversClass(CompletionRequest::class)]
#[CoversClass(CompositeCompletionSource::class)]
#[CoversClass(ExpressionResolver::class)]
#[CoversClass(KeywordCandidates::class)]
#[CoversClass(MemberCandidates::class)]
#[CoversClass(NamedArgumentCandidates::class)]
#[CoversClass(ResolvedTypeOnly::class)]
#[CoversClass(SymbolCandidates::class)]
#[CoversClass(VariableCandidates::class)]
class CompletionHandlerIntegrationTest extends TestCase
{
    use BuildsKnowledgeStackTrait;
    use OpensDocumentsTrait;
    use WiresCompletionSourceTrait;

    private DocumentManager $documents;
    private MemoizingSyntaxSource $parser;
    private CountingSyntaxSource $counter;
    private SymbolSourceInterface $symbolSource;
    private SymbolResolver $symbolResolver;
    private CompletionHandler $handler;
    private TextDocumentSyncHandler $syncHandler;

    protected function setUp(): void
    {
        $this->documents = new DocumentManager();
        $production = ProductionSyntaxSource::create();
        $this->parser = $production->source;
        $this->counter = $production->counter;

        $fixturesRoot = __DIR__ . '/../Fixtures';
        $knowledge = $this->knowledgeStackForProjectRoot($fixturesRoot, $production);
        $this->symbolSource = $knowledge->source;

        $memberResolver = new MemberResolver($knowledge->source);
        $typeSource = new NativeTypeSource($knowledge->source, $memberResolver);
        $this->symbolResolver = new SymbolResolver(
            $this->parser,
            $knowledge->source,
            $memberResolver,
            $typeSource,
        );
        $this->handler = $this->makeHandler($this->symbolSource);
        $this->syncHandler = new TextDocumentSyncHandler($this->documents, $knowledge->sink, $knowledge->invalidator);
    }

    private function seedClass(string $fqn): void
    {
        $namespace = NamespaceName::namespaceOf($fqn);
        $short = NamespaceName::shortNameOf($fqn);
        $source = $namespace === ''
            ? "<?php\nclass {$short} {}\n"
            : "<?php\nnamespace {$namespace};\nclass {$short} {}\n";
        $uri = 'file:///seed/' . str_replace('\\', '_', $fqn) . '.php';
        $this->openDocument($uri, $source);
    }

    private function makeHandler(SymbolSourceInterface $symbolSource, bool $snippetSupport = false): CompletionHandler
    {
        $capabilities = self::createStub(SessionCapabilitiesProviderInterface::class);
        $capabilities->method('getSessionCapabilities')
            ->willReturn(new SessionCapabilities(snippetSupport: $snippetSupport));

        return new CompletionHandler(
            $this->documents,
            self::completionSourceFor($symbolSource, $this->symbolResolver, $capabilities),
        );
    }

    /**
     * @param array{items: list<CompletionItem>} $result
     *
     * @return CompletionItem
     */
    private static function itemFor(array $result, string $label): array
    {
        foreach ($result['items'] as $item) {
            if ($item['label'] === $label) {
                return $item;
            }
        }

        self::fail("no completion item labelled {$label}");
    }

    public function testCapsResultsAndReportsIncompleteWhenNavigationOverflows(): void
    {
        // A namespace with far more children than the cap floods navigation. The
        // response is capped and flagged incomplete; ranking runs before the cap,
        // so it keeps the first-sorted nodes rather than raw source order.
        $children = array_map(
            static fn(int $i): string => sprintf('Flood\\N%03d', $i),
            range(150, 1, -1),
        );
        $source = new class ($children) implements SymbolSourceInterface {
            /** @param list<string> $children */
            public function __construct(private readonly array $children)
            {
            }

            public function childrenOf(NamespaceName $namespace): NamespaceContents
            {
                return $namespace->path === 'Flood'
                    ? new NamespaceContents($this->children, [])
                    : new NamespaceContents([], []);
            }

            public function lookupClassLike(ClasslikeName $name): ?ClassInfo
            {
                return null;
            }

            public function lookupConstant(ConstantName $name): ?ConstantInfo
            {
                return null;
            }

            public function lookupFunction(FunctionName $name): ?FunctionInfo
            {
                return null;
            }

            public function search(string $prefix, NameKind $kind): array
            {
                return [];
            }
        };
        $handler = $this->makeHandler($source);
        $cursor = $this->openFixtureAtCursor('Namespacing/AbsoluteNavigation.php', 'flood_nav');

        $result = $handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertTrue($result['isIncomplete'], 'An overflowing result set is reported incomplete');
        self::assertCount(100, $result['items'], 'The result set is capped at the limit');
        $labels = array_column($result['items'], 'label');
        self::assertContains('N001\\', $labels, 'Ranking runs before the cap, keeping the first-sorted node');
        self::assertNotContains('N150\\', $labels, 'A node sorted past the cap is dropped');
    }

    public function testCapAppliesToUnrankedCompletions(): void
    {
        // Flat class candidates (like members, variables, and functions) carry no
        // sortText. When more than the cap match, the response is still truncated
        // and flagged incomplete — the cap is a response-level limit, not one
        // special to navigation — via the sentinel that sorts unranked items last.
        foreach (range(0, 100) as $i) {
            $this->seedClass(sprintf('FloodClass%03d', $i));
        }
        $this->openDocument('file:///flood.php', '<?php new FloodClass');

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///flood.php'],
                'position' => ['line' => 0, 'character' => 20],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertTrue($result['isIncomplete'], 'An overflowing unranked result set is reported incomplete');
        self::assertCount(100, $result['items'], 'Unranked completions are capped at the limit');
    }

    public function testFunctionCompletion(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/FunctionCompletion.php', 'builtin_function');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Should include built-in functions starting with "arr"
        self::assertContains('array_map', $labels);
        self::assertContains('array_filter', $labels);
    }

    /**
     * @param non-empty-string $fixture
     * @param non-empty-string $marker
     */
    #[DataProvider('callableSnippetCases')]
    public function testCallableCompletionInsertsSnippetWhenClientSupportsIt(
        string $fixture,
        string $marker,
        string $label,
    ): void {
        $handler = $this->makeHandler($this->symbolSource, snippetSupport: true);
        $cursor = $this->openFixtureAtCursor($fixture, $marker);

        $result = $handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $item = self::itemFor($result, $label);
        self::assertSame(
            $label . '($0)',
            $item['insertText'] ?? null,
            'a snippet-capable client gets the parentheses typed with the cursor between them',
        );
        self::assertSame(
            InsertTextFormat::Snippet->value,
            $item['insertTextFormat'] ?? null,
            'insertText is only interpreted as a snippet when tagged as one',
        );
    }

    public function testCallableCompletionOmitsSnippetWithoutClientSupport(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/MethodAccess.php', 'this_empty');

        // The default handler is built with snippetSupport off.
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $item = self::itemFor($result, 'setName');
        self::assertArrayNotHasKey(
            'insertText',
            $item,
            'a client without snippet support inserts the bare label, so no snippet text is emitted',
        );
        self::assertArrayNotHasKey('insertTextFormat', $item, 'nothing marks the item as a snippet');
    }

    public function testNonCallableMemberNeverInsertsSnippet(): void
    {
        $handler = $this->makeHandler($this->symbolSource, snippetSupport: true);
        $cursor = $this->openFixtureAtCursor('src/Completion/MethodAccess.php', 'this_empty');

        $result = $handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $property = self::itemFor($result, 'name');
        self::assertArrayNotHasKey(
            'insertText',
            $property,
            'a property is not callable, so no parentheses are inserted even when snippets are supported',
        );
    }

    /**
     * @codeCoverageIgnore
     *
     * @return iterable<string, array{non-empty-string, non-empty-string, string}>
     */
    public static function callableSnippetCases(): iterable
    {
        yield 'method' => ['src/Completion/MethodAccess.php', 'this_empty', 'setName'];
        yield 'user function' => ['src/Completion/FunctionCompletion.php', 'user_function', 'calculateSum'];
        yield 'builtin function' => ['src/Completion/FunctionCompletion.php', 'builtin_function', 'array_map'];
    }

    /**
     * One parse per handled message. Completion is the fan-out
     * that made this matter — its sources each call a different CodeResolverInterface
     * method, and every one of them re-parsed the same unchanged document.
     *
     * These fixtures are chosen to resolve nothing from disk, so the total parse
     * count *is* the open document's count. Parses of other documents are a
     * separate cost that dedup does not remove and is not meant to: they are
     * memoized per class by the repository.
     */
    #[DataProvider('singleParseCompletions')]
    public function testCompletionParsesTheDocumentOnce(string $fixture, string $marker): void
    {
        $cursor = $this->openFixtureAtCursor($fixture, $marker);
        // didOpen is a message of its own; the server discards its parses before
        // the completion request is handled.
        $this->parser->endMessage();
        $before = $this->counter->parseCount;

        $this->handler->handle($this->completionRequestAt($cursor));

        $parsesForRequest = $this->counter->parseCount - $before;
        self::assertSame(1, $parsesForRequest, 'the whole request costs one parse');
    }

    /**
     * Distinct completion kinds, so more than one source fan-out is pinned.
     *
     * @return iterable<string, array{string, string}>
     *
     * @codeCoverageIgnore
     */
    public static function singleParseCompletions(): iterable
    {
        yield 'variable prefix' => ['src/Completion/Variables.php', 'param_prefix'];
        yield 'member access' => ['src/Completion/MethodAccess.php', 'this_empty'];
        yield 'static access' => ['src/Completion/StaticAccess.php', 'self_empty'];
    }

    public function testCompletionReturnsEmptyForUnknownContext(): void
    {
        $code = '<?php $x = 1;';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 12],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertEmpty($result['items']);
    }

    public function testUserDefinedFunctionCompletion(): void
    {
        $cursor = $this->openFixtureAtCursor('FunctionCompletion.php', 'user_defined_function');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $items = $result['items'];
        $labels = array_column($items, 'label');

        self::assertContains('calculateSum', $labels, 'calculateSum should be in completions');

        $functionItem = null;
        foreach ($items as $item) {
            if ($item['label'] === 'calculateSum') {
                $functionItem = $item;
                break;
            }
        }

        self::assertNotNull($functionItem);
        self::assertSame(3, $functionItem['kind'] ?? null); // KIND_FUNCTION
        $detail = $functionItem['detail'] ?? '';
        self::assertStringContainsString('function calculateSum', $detail);
        self::assertStringContainsString('int $a', $detail);
        self::assertStringContainsString('int $b', $detail);
        self::assertStringContainsString(': int', $detail);
        self::assertStringContainsString('Adds two numbers', $functionItem['documentation'] ?? '');
    }

    public function testConditionallyDeclaredFunctionCompletion(): void
    {
        $cursor = $this->openFixtureAtCursor('FunctionCompletion.php', 'user_defined_function');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertContains(
            'calculateProduct',
            array_column($result['items'], 'label'),
            'a function_exists-guarded polyfill is a name the file declares, so completion must offer it '
            . '— hover already resolves one, and a name visible to lookup but not to enumeration is the '
            . 'split RFC 1 §4.2 forbids',
        );
    }

    // =========================================================================
    // Context-based filtering
    // =========================================================================

    public function testNoCompletionsInComment(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/ContextFiltering.php', 'in_comment');
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        self::assertSame([], $result['items'], 'No completions should be offered inside comments');
    }

    public function testNoCompletionsForMemberAccessInComment(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/ContextFiltering.php', 'member_in_comment');
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        self::assertSame([], $result['items'], 'No completions for $this-> inside comments');
    }

    public function testOnlyVariablesInHeredoc(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/ContextFiltering.php', 'in_heredoc');
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);

        foreach ($result['items'] as $item) {
            self::assertSame(
                6, // KIND_VARIABLE
                $item['kind'] ?? 0,
                "Only variable completions should be offered in heredoc, got: {$item['label']}",
            );
        }
    }

    public function testImplementsAcrossMultipleLinesOffersInterfaces(): void
    {
        self::markTestSkipped(
            'Wrapped/multi-line implements is not yet handled: the classifier is single-line, '
            . 'so continuation lines fall through to Expression completion. See issue #310.',
        );

        // @phpstan-ignore-next-line deadCode.unreachable (documents the target behavior; unskip with #310)
        $cursor = $this->openFixtureAtCursor('src/Completion/WrappedImplementsCompletion.php', 'wrapped_implements');
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('Entity', $labels, 'Interfaces are valid in a wrapped implements list');

        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            'Functions must not leak into a wrapped implements list (issue #310)',
        );
    }
}
