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

    public function testUnrelatedNamespaceClassNotOfferedUnqualified(): void
    {
        $this->openFixture('Namespacing/UnrelatedNamespaceClass.php');
        $cursor = $this->openFixtureAtCursor('Namespacing/UnqualifiedNewCompletion.php', 'unqualified_new');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        // label => the FQCN it resolves to, so the reference is verified against
        // the actual class, not just its spelling.
        $byLabel = array_column($result['items'], 'detail', 'label');
        self::assertSame(
            'App\Theme',
            $byLabel['Theme'] ?? null,
            'A class in the current namespace is offered bare and resolves back to itself',
        );
        self::assertNotContains(
            'Other\Thing',
            $byLabel,
            'The unrelated-namespace class is offered under no reference at all, not merely not as a bare name',
        );
    }

    public function testSubNamespaceClassOfferedWithRelativeReference(): void
    {
        $this->openFixture('Namespacing/SubNamespaceClass.php');
        $cursor = $this->openFixtureAtCursor('Namespacing/UnqualifiedNewCompletion.php', 'subnamespace_new');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $byLabel = array_column($result['items'], 'detail', 'label');
        self::assertSame(
            'App\Sub\Thing',
            $byLabel['Sub\Thing'] ?? null,
            'A sub-namespace class is offered as the relative reference that resolves to exactly it',
        );
        self::assertArrayNotHasKey(
            'Thing',
            $byLabel,
            'It must not be offered bare, which would resolve to a different, nonexistent class',
        );
    }

    public function testQualifiedClassCarriesFilterTextAndTextEdit(): void
    {
        $this->openFixture('Namespacing/SubNamespaceClass.php');
        $cursor = $this->openFixtureAtCursor('Namespacing/UnqualifiedNewCompletion.php', 'subnamespace_new');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $item = null;
        foreach ($result['items'] as $candidate) {
            if ($candidate['label'] === 'Sub\Thing') {
                $item = $candidate;
                break;
            }
        }
        self::assertIsArray($item, 'The sub-namespace class is offered as `Sub\Thing`');

        self::assertSame(
            'Thing',
            $item['filterText'] ?? null,
            'The short name is the filter text, so the client keeps the item when the short prefix is typed',
        );
        self::assertSame(
            [
                'range' => [
                    'start' => ['line' => $cursor['line'], 'character' => $cursor['character'] - 2],
                    'end' => ['line' => $cursor['line'], 'character' => $cursor['character']],
                ],
                'newText' => 'Sub\Thing',
            ],
            $item['textEdit'] ?? null,
            'The textEdit replaces the whole typed prefix (`Th`) with the reference, so it never duplicates',
        );
    }

    public function testSameShortNameInTwoNamespacesEachResolvesDistinctly(): void
    {
        $this->openFixture('Namespacing/SubNamespaceClass.php');
        $this->openFixture('Namespacing/SecondSubNamespaceClass.php');
        $cursor = $this->openFixtureAtCursor('Namespacing/UnqualifiedNewCompletion.php', 'subnamespace_new');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $byLabel = array_column($result['items'], 'detail', 'label');
        self::assertSame(
            'App\Sub\Thing',
            $byLabel['Sub\Thing'] ?? null,
            'Two classes share the short name Thing; each is offered under the reference that resolves to it',
        );
        self::assertSame(
            'App\Deep\Thing',
            $byLabel['Deep\Thing'] ?? null,
            'The other Thing resolves to its own FQCN under its own reference',
        );
        self::assertArrayNotHasKey(
            'Thing',
            $byLabel,
            'Neither is offered bare, which would be ambiguous and resolve to neither',
        );
    }

    public function testBuiltinClassLikesAreNotOfferedInNamespacedNew(): void
    {
        // No dependencies opened: only built-ins exist, and #331 sources classes
        // from the workspace and imports — never from reflection or vendor.
        $cursor = $this->openFixtureAtCursor('Namespacing/UnqualifiedNewCompletion.php', 'builtin_new');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertNotContains(
            'Exception',
            $labels,
            'A built-in class-like is not a candidate here; reaching it is navigation (\\Exception), owned by #330',
        );
    }

    public function testCatalogOffersOnDiskClassNeverOpened(): void
    {
        // NOTHING here opens Fixtures\Domain\User. It exists only on disk, and is
        // discoverable solely through Composer's autoload map (the fixtures vendor
        // project). If it shows up, the catalog put an unopened class into a
        // completion response end-to-end.
        $cursor = $this->openFixtureAtCursor('Namespacing/CatalogProbe.php', 'ondisk_class');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $byLabel = array_column($result['items'], 'detail', 'label');
        self::assertSame(
            'Fixtures\Domain\User',
            $byLabel['User'] ?? null,
            'A class that was never opened is offered, discovered on disk via the catalog',
        );
    }

    public function testNavigationExcludesNonInstantiableOnDiskClassLikes(): void
    {
        // Psr\Http\Message\RequestInterface exists on disk (fixtures vendor) but is
        // an interface — invalid after `new`. It must be filtered by the same
        // predicate the index and imports use, not offered just because the catalog
        // discovered it.
        $cursor = $this->openFixtureAtCursor('Namespacing/CatalogProbe.php', 'ondisk_interface');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertNotContains(
            'RequestInterface',
            array_column($result['items'], 'label'),
            'An interface discovered on disk is not offered after `new`',
        );
    }

    public function testNavigationDropsDirectoryListingPhantoms(): void
    {
        // Fixtures\Catalog holds a real class (Fixture.php) beside a helpers file
        // (functions.php). The catalog reports both as coarse class-likes from the
        // directory listing; the existence gate must offer the class and drop the
        // phantom that has no class-like behind it.
        $cursor = $this->openFixtureAtCursor('Namespacing/CatalogProbe.php', 'ondisk_phantom');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('Fixture', $labels, 'The real class in the navigated namespace is offered');
        self::assertNotContains(
            'functions',
            $labels,
            'A functions.php phantom from the directory listing is not offered as a class',
        );
    }

    public function testNavigationOffersClassLikesDeclaredInAnAutoloadFilesEntry(): void
    {
        // Fixtures\Helpers is declared by an `autoload.files` entry, so it sits
        // outside every PSR-4 prefix and no directory listing can reach it. Its
        // class-likes resolve on hover and definition, so completion must see them
        // too (RFC 1 §4.2). The `new` filter still applies: of the four flavours the
        // entry declares, only the class is instantiable.
        $cursor = $this->openFixtureAtCursor('Namespacing/CatalogProbe.php', 'ondisk_autoload_files');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains(
            'HelperRegistry',
            $labels,
            'a class declared in an autoload.files entry is offered, not just resolvable',
        );
        self::assertNotContains(
            'HelperContract',
            $labels,
            'an interface from the same entry is filtered by the `new` predicate like any other',
        );
    }

    #[DataProvider('provideAbsoluteNavigationMarkers')]
    public function testAbsoluteNavigationFiresInEveryClassPosition(string $marker): void
    {
        // `new \Ps` already navigates; navigation must fire the same way in every
        // other absolute class position (catch, parameter type, return type). The
        // Psr\ node is a child namespace, offered regardless of the position filter,
        // so its presence proves navigation ran there.
        $cursor = $this->openFixtureAtCursor('Namespacing/AbsoluteNavigation.php', $marker);

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $modules = [];
        foreach ($result['items'] as $item) {
            if (($item['kind'] ?? null) === CompletionItemKind::Module->value) {
                $modules[] = $item['label'];
            }
        }
        $psrNodes = array_filter($modules, static fn(string $label): bool => str_starts_with($label, 'Psr\\'));
        self::assertNotEmpty(
            $psrNodes,
            "Namespace navigation fires in the {$marker} position, offering a Psr-rooted node",
        );
    }

    /**
     * @codeCoverageIgnore
     * @return iterable<string, array{string}>
     */
    public static function provideAbsoluteNavigationMarkers(): iterable
    {
        yield 'catch clause' => ['catch_nav'];
        yield 'parameter type' => ['param_nav'];
        yield 'return type' => ['return_nav'];
    }

    public function testBackslashNavigationOffersNamespaceNodes(): void
    {
        $cursor = $this->openFixtureAtCursor('Namespacing/UnqualifiedNewCompletion.php', 'nav_global');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $modules = [];
        foreach ($result['items'] as $item) {
            if (($item['kind'] ?? null) === CompletionItemKind::Module->value) {
                $modules[] = $item['label'];
            }
        }
        // The fixtures install only psr/http-message, so Psr has a single child
        // (Http) and is inlined rather than offered as a bare node — proving the
        // inline heuristic fires against the real catalog, not just a stub.
        self::assertContains(
            'Psr\\Http\\',
            $modules,
            'Typing `new \\Ps` navigates the global namespace; a one-child Psr inlines to its Http node',
        );
        self::assertNotContains('Psr\\', $modules, 'The inlined namespace is not also offered as a bare node');
        self::assertFalse($result['isIncomplete'], 'A result set within the cap is complete');
    }

    public function testUseStatementNavigatesFromGlobalNamespace(): void
    {
        // Issue #40: typing a `use` import offers namespaces from the global
        // namespace, even in a file whose own namespace is `App` — a `use` name is
        // absolute. The fixtures install only psr/http-message, so Psr has a single
        // child (Http) and inlines to its Http node, exactly as `new \Ps` does.
        $cursor = $this->openFixtureAtCursor('Namespacing/UseCompletion.php', 'use_first_segment');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $modules = [];
        foreach ($result['items'] as $item) {
            if (($item['kind'] ?? null) === CompletionItemKind::Module->value) {
                $modules[] = $item['label'];
            }
        }
        self::assertContains(
            'Psr\\Http\\',
            $modules,
            'A `use` import navigates the global namespace absolutely, ignoring the file\'s own `App` namespace',
        );
    }

    public function testUseStatementOffersWorkspaceClassLeaf(): void
    {
        // A `use` import navigates workspace/vendor namespaces through the catalog:
        // Fixtures\Domain\ is a PSR-4 namespace on disk, so its classes are offered
        // by their leaf name without the file being open.
        $cursor = $this->openFixtureAtCursor('Namespacing/UseCompletion.php', 'use_workspace_class');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertContains(
            'User',
            array_column($result['items'], 'label'),
            'A class in a navigated workspace namespace is offered by its leaf name in a `use` import',
        );
    }

    public function testTraitUseInClassBodyOffersTraitsOnly(): void
    {
        $cursor = $this->openFixtureAtCursor('Namespacing/TraitUseCompletion.php', 'trait_use');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains(
            'HasTimestamps',
            $labels,
            'Imported trait is offered by its short name (import-based resolution)',
        );
        $modules = array_filter(
            $result['items'],
            static fn(array $item): bool => ($item['kind'] ?? null) === CompletionItemKind::Module->value,
        );
        self::assertSame(
            [],
            $modules,
            'No namespace-navigation nodes — trait use is not import navigation',
        );
        self::assertNotContains(
            'HasThing',
            $labels,
            'Non-trait class-like (imported as HasThing) is excluded from trait use',
        );
        $keywords = array_filter(
            $result['items'],
            static fn(array $item): bool => ($item['kind'] ?? null) === CompletionItemKind::Keyword->value,
        );
        self::assertSame(
            [],
            $keywords,
            'No keywords — trait use offers only class-likes, not expression completions',
        );
    }

    public function testTraitUseDiscoversSameNamespaceTraitWithoutImport(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Traits/TraitUseFromSameNamespace.php', 'same_ns_trait');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains(
            'SingletonTrait',
            $labels,
            'Same-namespace trait is offered without import or opening its file',
        );
        self::assertNotContains(
            'ConcreteService',
            $labels,
            'Same-namespace non-trait class is excluded from trait use',
        );
    }

    public function testClosureUseCaptureIsNotImportNavigation(): void
    {
        // A closure's `use (...)` clause captures variables from the enclosing
        // scope; it shares the `use` keyword with an import but is an unrelated
        // construct. Like a trait `use`, it must never enter the absolute namespace
        // navigation an import `use` triggers — no namespaces, no class-likes.
        $cursor = $this->openFixtureAtCursor('Namespacing/ClosureUseCompletion.php', 'closure_capture');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $navigation = array_filter(
            $result['items'],
            static fn(array $item): bool => in_array(
                $item['kind'] ?? null,
                [CompletionItemKind::Module->value, CompletionItemKind::Class_->value],
                true,
            ),
        );
        self::assertSame(
            [],
            $navigation,
            'A closure `use` offers no namespace or class navigation; it is not import navigation',
        );
    }

    public function testBackslashNavigationOffersGlobalBuiltinClasses(): void
    {
        // Issue #38: typing an absolute name whose prefix matches a class declared
        // directly in the global namespace (`new \Spl`) offers that built-in class as
        // a leaf, not merely a namespace node. SplFixedArray is instantiable, so the
        // `new` position's Instantiable filter keeps it.
        $cursor = $this->openFixtureAtCursor('Namespacing/UnqualifiedNewCompletion.php', 'nav_global_class');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $classLeaves = [];
        foreach ($result['items'] as $item) {
            if (($item['kind'] ?? null) === CompletionItemKind::Class_->value) {
                $classLeaves[] = $item['label'];
            }
        }
        self::assertContains(
            'SplFixedArray',
            $classLeaves,
            'Navigating `new \\Spl` offers the instantiable global built-in class as a leaf',
        );
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

    #[DataProvider('provideQualifiedImportedPrefixMarkers')]
    public function testQualifiedImportedPrefixOffersLeafChild(string $marker): void
    {
        // Repository.php is NOT opened; it is discovered on disk through the catalog
        // via the `use Fixtures\Model\Env;` import. The child is offered by its leaf
        // (Repository) with the FQCN as detail — the typed `Env\` stands.
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportedPrefix.php', $marker);

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $byLabel = array_column($result['items'], 'detail', 'label');
        self::assertSame(
            'Fixtures\Model\Env\Repository',
            $byLabel['Repository'] ?? null,
            "The imported namespace's child is offered by its leaf with the FQCN detail",
        );
    }

    /**
     * @codeCoverageIgnore
     * @return iterable<string, array{string}>
     */
    public static function provideQualifiedImportedPrefixMarkers(): iterable
    {
        yield 'trailing slash' => ['imported_slash'];
        yield 'partial child' => ['imported_partial'];
    }

    public function testBareImportedPrefixInlinesSmallTarget(): void
    {
        // `new Env` reaches the same offerChildNamespace path as `new \Fixtures\Model\Env`,
        // so a small imported namespace inlines its members rather than offering a node.
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportedPrefix.php', 'imported_bare');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $byLabel = array_column($result['items'], 'detail', 'label');
        self::assertSame(
            'Fixtures\Model\Env\Repository',
            $byLabel['Env\Repository'] ?? null,
            'A small imported namespace inlines its members, identically to absolute navigation',
        );
        self::assertArrayNotHasKey('Env\\', $byLabel, 'A small target is not also offered as a bare node');
    }

    public function testBareImportedPrefixNodesLargeTarget(): void
    {
        // `use Psr\Http\Message;` then `new Message`: the target has many members, so
        // it stays a `Message\` node to step into — the Doctrine\ORM\Mapping case.
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportedPrefix.php', 'imported_large');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $byLabel = array_column($result['items'], 'detail', 'label');
        self::assertSame(
            'Psr\Http\Message',
            $byLabel['Message\\'] ?? null,
            'A large imported namespace stays a descent node',
        );
    }

    public function testQualifiedImportedPrefixInsertsLeafWithoutDuplication(): void
    {
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportedPrefix.php', 'imported_partial');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $byLabel = [];
        foreach ($result['items'] as $item) {
            $byLabel[$item['label']] = $item['textEdit']['newText'] ?? null;
        }
        self::assertSame(
            'Repository',
            $byLabel['Repository'] ?? null,
            'At `new Env\\R` only the leaf is inserted, so the typed `Env\\` is never duplicated',
        );
    }

    public function testImportedPrefixExcludesInterfaceAfterNew(): void
    {
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportedPrefix.php', 'imported_iface_new');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertNotContains(
            'Handler',
            array_column($result['items'], 'label'),
            'An interface child is not offered after `new`',
        );
    }

    public function testImportedPrefixOffersInterfaceAsTypeHint(): void
    {
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportedPrefix.php', 'imported_iface_type');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertContains(
            'Handler',
            array_column($result['items'], 'label'),
            'An interface child is offered in a type-hint position',
        );
    }

    public function testImportedPrefixDoesNotLeakMembersWhenChildIsOpen(): void
    {
        // Opening the child's file must not cause its methods to be offered as
        // class candidates — the reverted #331 attempt's regression.
        $this->openFixture('src/Model/Env/Repository.php');
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportedPrefix.php', 'imported_slash');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('Repository', $labels, 'The class child is still offered');
        self::assertNotContains('persist', $labels, "The child's method is not offered as a class candidate");
    }

    public function testImportedPrefixOffersNothingForUnmatchedChild(): void
    {
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportedPrefix.php', 'imported_no_match');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertNotContains('Repository', $labels, 'No child matches `Env\\Zz`, so none is offered');
        self::assertNotContains('Handler', $labels, 'No child matches `Env\\Zz`, so none is offered');
    }

    public function testImportedPrefixNavigatesDeeperNamespaces(): void
    {
        // `Env\Sub\T` descends two levels: the enumerated namespace is the import
        // target plus the middle path, and only the leaf (Thing) is inserted.
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportedPrefix.php', 'imported_deep');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $byLabel = array_column($result['items'], 'detail', 'label');
        self::assertSame(
            'Fixtures\Model\Env\Sub\Thing',
            $byLabel['Thing'] ?? null,
            'A grandchild is reached and offered by its leaf',
        );
    }

    public function testCurrentNamespaceChildIsNavigableWithoutAnImport(): void
    {
        // In a file whose namespace is Fixtures\Model, `Env` is a child namespace,
        // not an import. `new Env\R` still resolves it relative to the current
        // namespace and offers Repository.
        $cursor = $this->openFixtureAtCursor('Namespacing/CurrentNamespaceProbe.php', 'current_ns_child');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $byLabel = array_column($result['items'], 'detail', 'label');
        self::assertSame(
            'Fixtures\Model\Env\Repository',
            $byLabel['Repository'] ?? null,
            'A child of the current namespace is navigable without an import',
        );
    }

    public function testUnimportedUnrelatedPrefixIsNotReached(): void
    {
        // `new Other\R`: Other is neither an import nor a child of the current
        // namespace, so it resolves to a namespace that does not exist. Nothing is
        // offered — in particular Env's children (Repository/Handler) do not leak in.
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportedPrefix.php', 'imported_unrelated');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertSame(
            [],
            $result['items'],
            'A prefix that is neither imported nor a current-namespace child reaches nothing',
        );
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

    public function testExpressionCompletionIncludesImportedClasses(): void
    {
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportCompletion.php', 'imported_class_partial');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Should include imported class
        self::assertContains('User', $labels);

        // Check that FQCN is in detail
        $userItems = array_filter($result['items'], fn($item) => $item['label'] === 'User');
        self::assertNotEmpty($userItems);
        $userItem = reset($userItems);
        self::assertIsArray($userItem);
        self::assertSame('Fixtures\Namespacing\Models\User', $userItem['detail'] ?? null);
    }

    public function testExpressionCompletionIncludesAliasedImports(): void
    {
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportCompletion.php', 'aliased_class_partial');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Should include aliased import
        self::assertContains('Repo', $labels);

        // Check that FQCN is in detail
        $repoItems = array_filter($result['items'], fn($item) => $item['label'] === 'Repo');
        self::assertNotEmpty($repoItems);
        $repoItem = reset($repoItems);
        self::assertIsArray($repoItem);
        self::assertSame('Fixtures\Namespacing\Models\UserRepository', $repoItem['detail'] ?? null);
    }

    public function testExpressionCompletionIncludesGroupedImports(): void
    {
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportCompletion.php', 'grouped_import_partial');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Should include both grouped imports
        self::assertContains('User', $labels);

        // Check that FQCN is correct for grouped import
        $userItems = array_filter($result['items'], fn($item) => $item['label'] === 'User');
        self::assertNotEmpty($userItems);
        $userItem = reset($userItems);
        self::assertIsArray($userItem);
        self::assertSame('Fixtures\Namespacing\Models\User', $userItem['detail'] ?? null);
    }

    public function testExpressionCompletionKeepsClassAndFunctionImportsApart(): void
    {
        // Models\Widget (class) and Helpers\Widget (function) are both imported
        // under the same short name; the class completion must offer the class.
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportCompletion.php', 'colliding_partial');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $widgetItems = array_filter($result['items'], fn($item) => $item['label'] === 'Widget');
        self::assertNotEmpty($widgetItems, 'The imported class should be offered');
        $widgetItem = reset($widgetItems);
        self::assertIsArray($widgetItem);
        self::assertSame(
            'Fixtures\Namespacing\Models\Widget',
            $widgetItem['detail'] ?? null,
            'A `use function` of the same short name must not shadow the class import',
        );
    }

    public function testNewCompletionIncludesIndexedClasses(): void
    {
        // Add a class to the workspace via the sink
        $this->seedClass('MyIndexedClass');

        $code = '<?php $x = new MyIn';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 19],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('MyIndexedClass', $labels);
    }

    public function testNewCompletionExcludesIndexedInterfaces(): void
    {
        $this->openDocument('file:///class.php', "<?php\nclass MyClass {}\n");
        $this->openDocument('file:///iface.php', "<?php\ninterface MyInterface {}\n");

        $code = '<?php $x = new My';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 17],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('MyClass', $labels);
        self::assertNotContains('MyInterface', $labels);
    }

    public function testNewCompletionExcludesAbstractClasses(): void
    {
        // Index the classes first (AbstractBase is abstract, SealedClass is concrete)
        $this->openFixture('src/Utility/ClassModifiers.php');
        $cursor = $this->openFixtureAtCursor('src/Completion/NewCompletion.php', 'new_abstract');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // Should include concrete class (imported via use statement)
        self::assertContains('SealedClass', $labels);
        // Should NOT include abstract class
        self::assertNotContains('AbstractBase', $labels);
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

    public function testExpressionCompletionIncludesAllIndexedTypes(): void
    {
        $this->openDocument('file:///class.php', "<?php\nclass ZqClass {}\n");
        $this->openDocument('file:///iface.php', "<?php\ninterface ZqInterface {}\n");
        $this->openDocument('file:///trait.php', "<?php\ntrait ZqTrait {}\n");

        $code = '<?php $x = Zq';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 13],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('ZqClass', $labels);
        self::assertContains('ZqInterface', $labels);
        self::assertContains('ZqTrait', $labels);
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

    public function testAttributeContextOffersOnlyAttributes(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/AttributeCompletion.php', 'attr_empty');
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('Route', $labels, 'Imported attribute classes are offered in a #[ position');
        self::assertContains('NoConstructorAttribute', $labels, 'Attribute classes without a constructor are offered');
        self::assertNotContains('User', $labels, 'A plain class is not an attribute');
        self::assertNotContains('Entity', $labels, 'An interface is not an attribute');
        self::assertNotContains('SingletonTrait', $labels, 'A trait is not an attribute');
        self::assertNotContains('Status', $labels, 'An enum is not an attribute');

        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            'Functions must not leak into a #[ position (issue #252)',
        );
        self::assertNotContains(
            CompletionItemKind::Keyword->value,
            $kinds,
            'Keywords must not leak into a #[ position',
        );
    }

    public function testAttributeWithPrefixOffersAttributeNotFunctions(): void
    {
        // The #[ analog of the #298 report: `#[date` must not leak `date_*` functions.
        $cursor = $this->openFixtureAtCursor('src/Completion/AttributePrefixCompletion.php', 'attr_prefix');
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('Route', $labels, 'An in-scope attribute matching the prefix is offered');

        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            'Functions must not be offered in a #[ position (issue #252)',
        );
    }

    public function testAttributeGroupedContinuationOffersAttributes(): void
    {
        // Grouped attributes: `#[A, B` — the position after the comma is still an
        // attribute-name position.
        $cursor = $this->openFixtureAtCursor('src/Completion/AttributeGroupedCompletion.php', 'attr_grouped');
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('Route', $labels, 'Attributes are offered after a comma in a grouped attribute');

        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            'Functions must not leak into a grouped attribute (issue #252)',
        );
    }

    /**
     * Interface-list positions (`implements` and `interface … extends`) share one
     * behavior: interfaces from imports/index are offered, everything else is not.
     * The cases are data-driven so each new interface-list position (#312–#316)
     * plugs in as a row rather than a copy-pasted method.
     *
     * @param list<string> $expectedPresent interface labels that must be offered
     * @param list<string> $expectedAbsentLabels non-interface labels that must not be offered
     * @param list<CompletionItemKind> $expectedAbsentKinds item kinds that must not leak in
     */
    #[DataProvider('provideInterfaceListContexts')]
    public function testInterfaceListContextOffersOnlyInterfaces(
        string $fixture,
        string $marker,
        array $expectedPresent,
        array $expectedAbsentLabels,
        array $expectedAbsentKinds,
    ): void {
        $cursor = $this->openFixtureAtCursor($fixture, $marker);
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        foreach ($expectedPresent as $label) {
            self::assertContains($label, $labels, "Interface {$label} must be offered in an interface list");
        }
        foreach ($expectedAbsentLabels as $label) {
            self::assertNotContains($label, $labels, "{$label} is not an interface and must not be offered");
        }

        $kinds = array_column($result['items'], 'kind');
        foreach ($expectedAbsentKinds as $kind) {
            self::assertNotContains(
                $kind->value,
                $kinds,
                "{$kind->name} items must not leak into an interface list",
            );
        }
    }

    /**
     * @codeCoverageIgnore
     * @return iterable<string, array{
     *   string,
     *   string,
     *   list<string>,
     *   list<string>,
     *   list<CompletionItemKind>,
     * }>
     */
    public static function provideInterfaceListContexts(): iterable
    {
        // `implements` list (issue #298).
        yield 'implements empty' => [
            'src/Completion/ImplementsCompletion.php',
            'implements_empty',
            ['Entity'],
            ['User', 'SingletonTrait', 'Status'],
            [CompletionItemKind::Function, CompletionItemKind::Keyword],
        ];
        // The original #298 report: `implements D` offered `date_*` functions
        // instead of the in-scope interface starting with `D`.
        yield 'implements D prefix' => [
            'src/Completion/ImplementsPrefixCompletion.php',
            'implements_d_prefix',
            ['Describable'],
            ['date_add'],
            [CompletionItemKind::Function],
        ];
        // Exercises the reflection resolution path: an imported built-in interface
        // is offered while the built-in class of the same prefix is excluded.
        yield 'implements builtin' => [
            'src/Completion/ImplementsBuiltinCompletion.php',
            'implements_builtin',
            ['SessionHandlerInterface'],
            ['SessionHandler', 'session_start'],
            [CompletionItemKind::Function],
        ];

        // `interface … extends` list (issue #312).
        yield 'interface extends empty' => [
            'src/Completion/InterfaceExtendsCompletion.php',
            'interface_extends_empty',
            ['Entity'],
            ['User', 'SingletonTrait', 'Status'],
            [CompletionItemKind::Function, CompletionItemKind::Keyword],
        ];
        yield 'interface extends D prefix' => [
            'src/Completion/InterfaceExtendsPrefixCompletion.php',
            'interface_extends_d_prefix',
            ['Describable'],
            ['date_add'],
            [CompletionItemKind::Function],
        ];
        // The comma-list form: an interface may extend several interfaces.
        yield 'interface extends list continuation' => [
            'src/Completion/InterfaceExtendsListCompletion.php',
            'interface_extends_list',
            ['Describable'],
            [],
            [CompletionItemKind::Function],
        ];
    }

    public function testInterfaceExtendsContextOffersOnlyInterfaces(): void
    {
        $cursor = $this->openFixtureAtCursor(
            'src/Completion/InterfaceExtendsCompletion.php',
            'interface_extends_empty',
        );
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('Entity', $labels, 'Imported interfaces are valid in an interface extends list');
        self::assertNotContains('User', $labels, 'An interface cannot extend a class');
        self::assertNotContains('SingletonTrait', $labels, 'An interface cannot extend a trait');
        self::assertNotContains('Status', $labels, 'An interface cannot extend an enum');

        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            'Functions must not leak into an interface extends list (issue #312)',
        );
        self::assertNotContains(
            CompletionItemKind::Keyword->value,
            $kinds,
            'Keywords must not leak into an interface extends list',
        );
    }

    public function testInterfaceExtendsWithPrefixOffersInterfaceNotFunctions(): void
    {
        // Mirrors the #298 report for the extends position: `extends D` must offer the
        // in-scope interface starting with `D`, not built-in `date_*` functions.
        $cursor = $this->openFixtureAtCursor(
            'src/Completion/InterfaceExtendsPrefixCompletion.php',
            'interface_extends_d_prefix',
        );
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('Describable', $labels, 'An in-scope interface starting with D should be offered');
        self::assertNotContains('date_add', $labels, 'Built-in functions must not be offered in an extends list');

        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            'No function should be offered in an interface extends list (issue #312)',
        );
    }

    public function testInterfaceExtendsListContinuationOffersInterfaces(): void
    {
        // The comma-list form: an interface may extend several interfaces.
        $cursor = $this->openFixtureAtCursor(
            'src/Completion/InterfaceExtendsListCompletion.php',
            'interface_extends_list',
        );
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains(
            'Describable',
            $labels,
            'Interfaces are valid after a comma in an interface extends list',
        );

        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            'Functions must not leak into a comma-separated interface extends list (issue #312)',
        );
    }

    public function testClassExtendsContextOffersOnlyExtendableClasses(): void
    {
        $cursor = $this->openFixtureAtCursor(
            'src/Completion/ClassExtendsCompletion.php',
            'class_extends_empty',
        );
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('ParentClass', $labels, 'A non-final class can be extended');
        self::assertNotContains('FinalDescendant', $labels, 'A final class cannot be extended');
        self::assertNotContains('Entity', $labels, 'A class cannot extend an interface');
        self::assertNotContains('SingletonTrait', $labels, 'A class cannot extend a trait');
        self::assertNotContains('Status', $labels, 'A class cannot extend an enum');

        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            'Functions must not leak into a class extends clause (issue #313)',
        );
        self::assertNotContains(
            CompletionItemKind::Keyword->value,
            $kinds,
            'Keywords must not leak into a class extends clause (issue #313)',
        );
    }

    public function testClassExtendsWithPrefixOffersClassNotFunctions(): void
    {
        // Mirrors the #298 report for the extends position: `extends P` must offer the
        // in-scope class starting with `P`, not built-in `printf`/`print_r`.
        $cursor = $this->openFixtureAtCursor(
            'src/Completion/ClassExtendsPrefixCompletion.php',
            'class_extends_p_prefix',
        );
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('ParentClass', $labels, 'An in-scope class starting with P should be offered');
        self::assertNotContains('printf', $labels, 'Built-in functions must not be offered in an extends clause');

        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            'No function should be offered in a class extends clause (issue #313)',
        );
    }

    public function testCatchContextOffersOnlyThrowables(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/CatchCompletion.php', 'catch_empty');
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('AppException', $labels, 'A Throwable subclass can be caught');
        self::assertContains('ExceptionInterface', $labels, 'An interface extending Throwable can be caught');
        self::assertNotContains('User', $labels, 'A non-Throwable class cannot be caught');
        self::assertNotContains('Entity', $labels, 'A non-Throwable interface cannot be caught');
        self::assertNotContains('SingletonTrait', $labels, 'A trait cannot be caught');
        self::assertNotContains('Status', $labels, 'An enum cannot be caught');

        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            'Functions must not leak into a catch clause (issue #314)',
        );
        self::assertNotContains(
            CompletionItemKind::Keyword->value,
            $kinds,
            'Keywords must not leak into a catch clause (issue #314)',
        );
    }

    public function testMultiCatchContinuationOffersThrowables(): void
    {
        // The `|`-separated multi-catch form must keep offering Throwables (issue #314).
        $cursor = $this->openFixtureAtCursor('src/Completion/MultiCatchCompletion.php', 'catch_multi');
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('ExceptionInterface', $labels, 'A Throwable is offered after the multi-catch pipe');
        self::assertNotContains('User', $labels, 'A non-Throwable class cannot be caught');
    }

    public function testCatchWithPrefixOffersThrowableNotFunctions(): void
    {
        // Mirrors the #298 report for the catch position: `catch (A` must offer the
        // in-scope Throwable starting with `A`, not built-in functions.
        $cursor = $this->openFixtureAtCursor('src/Completion/CatchPrefixCompletion.php', 'catch_a_prefix');
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('AppException', $labels, 'An in-scope Throwable starting with A should be offered');
        self::assertNotContains('array_map', $labels, 'Built-in functions must not be offered in a catch clause');

        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            'No function should be offered in a catch clause (issue #314)',
        );
    }

    public function testInstanceofOffersClassLikesNotTraits(): void
    {
        // Issue #315: the right-hand side of `instanceof` accepts classes, interfaces,
        // and enums, but not traits — `$x instanceof SomeTrait` is always false. Scalar
        // type keywords and functions are not valid there either.
        $cursor = $this->openFixtureAtCursor('src/Completion/InstanceofCompletion.php', 'instanceof_empty');
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('User', $labels, 'A class is valid on the right of instanceof');
        self::assertContains('Entity', $labels, 'An interface is valid on the right of instanceof');
        self::assertContains('Status', $labels, 'An enum is valid on the right of instanceof');
        self::assertNotContains('SingletonTrait', $labels, 'A trait instanceof check is always false');
        self::assertNotContains('string', $labels, 'A scalar type keyword is not valid after instanceof');

        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            'Functions must not be offered on the right of instanceof',
        );
        self::assertNotContains(
            CompletionItemKind::Keyword->value,
            $kinds,
            'Keywords must not be offered on the right of instanceof',
        );
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

    public function testExpressionCompletionOffersEverySearchableKind(): void
    {
        // Open the multi-namespace file so its functions and constants are
        // registered, then trigger expression completion from a file that
        // imports all three symbol kinds.
        $this->openFixture('Namespacing/MultiNamespaceImports.php');
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportCompletion.php', 'imported_function_partial');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $kindsPresent = array_unique(array_column($result['items'], 'kind'));
        self::assertContains(
            CompletionItemKind::Function->value,
            $kindsPresent,
            'Expression completion must offer functions',
        );

        // Now check class-likes at the class prefix marker
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportCompletion.php', 'imported_class_partial');
        $result = $this->handler->handle($this->completionRequestAt($cursor));
        self::assertIsArray($result);
        $kindsPresent = array_unique(array_column($result['items'], 'kind'));
        self::assertContains(
            CompletionItemKind::Class_->value,
            $kindsPresent,
            'Expression completion must offer class-likes',
        );

        // And constants at the constant prefix marker
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportCompletion.php', 'imported_constant_partial');
        $result = $this->handler->handle($this->completionRequestAt($cursor));
        self::assertIsArray($result);
        $kindsPresent = array_unique(array_column($result['items'], 'kind'));
        self::assertContains(
            CompletionItemKind::Constant->value,
            $kindsPresent,
            'Expression completion must offer constants',
        );
    }

    public function testExpressionCompletionOffersImportedFunction(): void
    {
        $this->openFixture('Namespacing/MultiNamespaceImports.php');
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportCompletion.php', 'imported_function_partial');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains(
            'makeUser',
            $labels,
            'A function imported via `use function` is offered at expression start',
        );
    }

    public function testExpressionCompletionOffersImportedConstant(): void
    {
        $this->openFixture('Namespacing/MultiNamespaceImports.php');
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportCompletion.php', 'imported_constant_partial');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains(
            'DEFAULT_LIMIT',
            $labels,
            'A constant imported via `use const` is offered at expression start',
        );
    }

    public function testExpressionCompletionOffersGlobalFunctionFromNamespace(): void
    {
        // A global built-in function is offered unqualified from inside a namespace
        // via the global-fallback rule, derived from ReferenceResolver rather than
        // an unconditional list.
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportCompletion.php', 'imported_class_partial');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        // "Us" prefix should not match functions, so use the function prefix marker
        $cursor = $this->openFixtureAtCursor('src/Completion/FunctionCompletion.php', 'builtin_function');
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains(
            'array_map',
            $labels,
            'A global built-in function is offered unqualified',
        );
    }

    public function testCurrentNamespaceClassOfferedWithoutOpening(): void
    {
        // A class in the current namespace exists on disk (via Composer's PSR-4)
        // but is never opened. It should be offered via childrenOf(current namespace).
        // Fixtures\Domain\User is in tests/Fixtures/src/Domain/User.php — PSR-4
        // autoloaded. The probe file sits in namespace Fixtures\Domain.
        $cursor = $this->openFixtureAtCursor('Namespacing/CurrentNamespaceBareProbe.php', 'current_ns_bare');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $byLabel = array_column($result['items'], 'detail', 'label');
        self::assertSame(
            'Fixtures\Domain\User',
            $byLabel['User'] ?? null,
            'A class in the current namespace is offered even when never opened (issue #383)',
        );
    }

    public function testBackslashNavigationOffersGlobalFunctionsAtExpressionStart(): void
    {
        // `\`-prefixed function completion works at expression start. The
        // classifier keeps the leading `\`, so navigation walks the global namespace
        // and offers reflected built-in functions matching the segment.
        $this->openDocument('file:///expr.php', '<?php $x = \\strle');

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///expr.php'],
                'position' => ['line' => 0, 'character' => 17],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $functionLabels = [];
        foreach ($result['items'] as $item) {
            if (($item['kind'] ?? null) === CompletionItemKind::Function->value) {
                $functionLabels[] = $item['label'];
            }
        }
        self::assertContains(
            'strlen',
            $functionLabels,
            'An absolute prefix at expression start navigates the global namespace and offers global functions',
        );
    }

    #[DataProvider('provideFilteredPositionMarkers')]
    public function testFilteredPositionNavigationOffersNoFunctionsOrConstants(string $marker): void
    {
        // Navigation in a class-only position offers class-likes only —
        // a function or constant leaf from the walked namespace must not leak.
        $cursor = $this->openFixtureAtCursor('Namespacing/AbsoluteNavigation.php', $marker);

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            "Navigation from the {$marker} position offers no function leaf",
        );
        self::assertNotContains(
            CompletionItemKind::Constant->value,
            $kinds,
            "Navigation from the {$marker} position offers no constant leaf",
        );
    }

    /**
     * @codeCoverageIgnore
     * @return iterable<string, array{string}>
     */
    public static function provideFilteredPositionMarkers(): iterable
    {
        yield 'catch clause' => ['catch_nav'];
        yield 'extends clause' => ['extends_nav'];
        yield 'implements clause' => ['implements_nav'];
        yield 'trait use' => ['trait_use_nav'];
        yield 'attribute' => ['attribute_nav'];
    }
}
