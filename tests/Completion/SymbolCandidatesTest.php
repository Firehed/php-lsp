<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Capability\SessionCapabilitiesProviderInterface;
use Firehed\PhpLsp\Completion\ClassCandidateFilter;
use Firehed\PhpLsp\Completion\CompletionItemKind;
use Firehed\PhpLsp\Completion\CompletionRequest;
use Firehed\PhpLsp\Completion\SymbolCandidates;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\CatalogSymbol;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceContents;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\Symbol;
use Firehed\PhpLsp\Domain\SymbolKind;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Protocol\Range;
use Firehed\PhpLsp\Resolution\CodeResolverInterface;
use Firehed\PhpLsp\Resolution\NameContext;
use Firehed\PhpLsp\Resolution\ResolvedSymbolPresenter;
use Firehed\PhpLsp\Tests\BuildsSymbolInfoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * How each symbol is written comes from ReferenceResolver, whose rules
 * ReferenceResolverTest owns; these tests cover which candidates are offered.
 */
#[CoversClass(SymbolCandidates::class)]
final class SymbolCandidatesTest extends TestCase
{
    use BuildsSymbolInfoTrait;

    public function testSearchHitIsOfferedByItsReferenceRankedByReach(): void
    {
        $items = self::candidates(new NameContext('App'), search: ['App\Widget'])
            ->find(self::requestAfter('$x = Wid'), [NameKind::ClassLike], ClassCandidateFilter::Any);

        self::assertSame(
            [[
                'label' => 'Widget',
                'kind' => CompletionItemKind::Class_->value,
                'detail' => 'App\Widget',
                'filterText' => 'Widget',
                'textEdit' => ['range' => Range::onLine(1, 5, 8)->toArray(), 'newText' => 'Widget'],
                'sortText' => '0_Widget',
            ]],
            $items,
            'the reference replaces the typed prefix and sorts by how near the symbol is',
        );
    }

    public function testUnreachableSearchHitIsDroppedBeforeTheFilterRuns(): void
    {
        $asked = [];
        $resolver = self::resolver(new NameContext('Elsewhere'));
        $resolver->method('isInstantiable')->willReturnCallback(
            static function (ClasslikeName $name) use (&$asked): bool {
                $asked[] = $name;
                return true;
            },
        );

        $items = self::candidates(new NameContext('Elsewhere'), search: ['App\Widget'], resolver: $resolver)
            ->find(self::requestAfter('$x = Wid'), [NameKind::ClassLike], ClassCandidateFilter::Instantiable);

        self::assertSame([], $items, 'a class-like that needs an import or a leading \\ is not offered bare');
        self::assertSame([], $asked, 'the cheap reachability check runs before the filter resolves the class');
    }

    public function testImportIsOfferedByItsAlias(): void
    {
        $items = self::candidates(new NameContext('App', classImports: ['Gadget' => 'Lib\Device']))
            ->find(self::requestAfter('$x = Ga'), [NameKind::ClassLike], ClassCandidateFilter::Any);

        self::assertSame(
            [[
                'label' => 'Gadget',
                'kind' => CompletionItemKind::Class_->value,
                'detail' => 'Lib\Device',
                'filterText' => 'Gadget',
                'textEdit' => ['range' => Range::onLine(1, 5, 7)->toArray(), 'newText' => 'Gadget'],
            ]],
            $items,
            'an import is offered by the alias it binds',
        );
    }

    public function testCurrentNamespaceSymbolIsOfferedWithoutASearchHit(): void
    {
        $children = ['App' => new NamespaceContents(symbols: [
            new CatalogSymbol('App\Widget', NameKind::ClassLike),
            new CatalogSymbol('App\Gizmo', NameKind::ClassLike),
            new CatalogSymbol('App\widget_count', NameKind::Function_),
        ])];

        self::assertSame(
            ['Widget'],
            self::labels(
                self::candidates(new NameContext('App'), children: $children)
                    ->find(self::requestAfter('$x = Wid'), [NameKind::ClassLike], ClassCandidateFilter::Any),
            ),
            'a symbol declared in the current namespace is offered when it matches and is of a requested kind',
        );
    }

    public function testGlobalNamespaceListingIsLeftToSearch(): void
    {
        $children = ['' => new NamespaceContents(symbols: [new CatalogSymbol('Widget', NameKind::ClassLike)])];

        self::assertSame(
            [],
            self::candidates(new NameContext(''), children: $children)
                ->find(self::requestAfter('$x = Wid'), [NameKind::ClassLike], ClassCandidateFilter::Any),
            'outside a namespace, only search offers global symbols',
        );
    }

    public function testFilterAppliesToEverySource(): void
    {
        $accepted = ['App\WidgetFound', 'Lib\WidgetImported', 'App\WidgetDeclared'];
        $resolver = self::resolver(new NameContext('App', classImports: [
            'WidgetImported' => 'Lib\WidgetImported',
            'WidgetRejectedImport' => 'Lib\WidgetRejectedImport',
        ]));
        $resolver->method('isInstantiable')->willReturnCallback(
            static fn (ClasslikeName $name): bool => self::isOneOf($name, $accepted),
        );
        $children = ['App' => new NamespaceContents(symbols: [
            new CatalogSymbol('App\WidgetDeclared', NameKind::ClassLike),
            new CatalogSymbol('App\WidgetRejectedDeclared', NameKind::ClassLike),
        ])];

        $items = self::candidates(
            new NameContext('App'),
            search: ['App\WidgetFound', 'App\WidgetRejectedFound'],
            children: $children,
            resolver: $resolver,
        )->find(self::requestAfter('$x = Wid'), [NameKind::ClassLike], ClassCandidateFilter::Instantiable);

        self::assertSame(
            ['WidgetFound', 'WidgetImported', 'WidgetDeclared'],
            self::labels($items),
            'search hits, imports, and current-namespace symbols all pass the position\'s filter',
        );
    }

    public function testSymbolReachedSeveralWaysIsOfferedOnce(): void
    {
        $children = ['App' => new NamespaceContents(symbols: [new CatalogSymbol('App\Widget', NameKind::ClassLike)])];
        $context = new NameContext('App', classImports: ['Wireless' => 'Lib\Wireless']);

        $items = self::candidates($context, search: ['App\Widget', 'Lib\Wireless'], children: $children)
            ->find(self::requestAfter('$x = Wi'), [NameKind::ClassLike], ClassCandidateFilter::Any);

        self::assertSame(
            ['Widget' => '0_Widget', 'Wireless' => '1_Wireless'],
            array_column($items, 'sortText', 'label'),
            'the search hits are offered, and the import and namespace listing of the same symbols are not',
        );
    }

    public function testNameSharedAcrossKindsIsOfferedOnce(): void
    {
        $items = self::candidates(
            new NameContext('App'),
            search: ['App\Thing'],
            functionSearch: ['App\Thing'],
        )->find(self::requestAfter('$x = Thi'), [NameKind::ClassLike, NameKind::Function_], ClassCandidateFilter::Any);

        self::assertSame(
            [CompletionItemKind::Class_->value],
            array_column($items, 'kind'),
            'a class and a function with one name are offered once, as the first requested kind',
        );
    }

    public function testShadowedNamespaceFunctionYieldsToTheImport(): void
    {
        $context = new NameContext('App', functionImports: ['calculateSum' => 'str_contains']);

        $children = ['App' => new NamespaceContents(symbols: [new CatalogSymbol('App\calculateSum', NameKind::Function_)])];

        $items = self::candidates(
            $context,
            functionSearch: ['App\calculateSum', 'App\calculateProduct'],
            children: $children,
        )->find(self::requestAfter('$x = calc'), [NameKind::Function_], ClassCandidateFilter::Any);

        self::assertSame(
            ['calculateProduct' => 'App\calculateProduct', 'calculateSum' => 'str_contains'],
            array_column($items, 'detail', 'label'),
            'an import of the same short name hides the namespace function, so the name means the import',
        );
    }

    public function testFunctionShowsItsSignature(): void
    {
        $info = self::functionInfo(QualifiedName::fromFullyQualified('App\count_widgets'));
        $symbols = self::symbolSource(functionSearch: ['App\count_widgets']);
        $symbols->method('lookupFunction')->willReturn($info);

        $items = (new SymbolCandidates($symbols, self::resolver(new NameContext('App')), self::capabilities()))
            ->find(self::requestAfter('$x = count_'), [NameKind::Function_], ClassCandidateFilter::Any);

        self::assertSame(
            [ResolvedSymbolPresenter::present($info)->signature],
            array_column($items, 'detail'),
            'a function shows its signature rather than its name',
        );
    }

    public function testCallableSnippetFollowsTheClient(): void
    {
        $items = self::candidates(
            new NameContext('App'),
            functionSearch: ['App\count_widgets'],
            capabilities: new SessionCapabilities(snippetSupport: true),
        )->find(self::requestAfter('$x = count_'), [NameKind::Function_], ClassCandidateFilter::Any);

        self::assertSame(
            ['count_widgets($0)'],
            array_column($items, 'insertText'),
            'a client that takes snippets gets the call parentheses',
        );
    }

    public function testAbsolutePrefixOffersMatchingContentsOfItsNamespace(): void
    {
        $children = [
            'Lib' => new NamespaceContents(
                childNamespaces: ['Lib\Web', 'Lib\Other'],
                symbols: [
                    new CatalogSymbol('Lib\Widget', NameKind::ClassLike),
                    new CatalogSymbol('Lib\wrap', NameKind::Function_),
                    new CatalogSymbol('Lib\Gadget', NameKind::ClassLike),
                ],
            ),
            'Lib\Web' => self::largeNamespace('Lib\Web'),
        ];

        $items = self::candidates(new NameContext('App'), children: $children)
            ->find(self::requestAfter('$x = \Lib\W'), [NameKind::ClassLike], ClassCandidateFilter::Any);

        self::assertSame(
            ['Web\\' => '1_Web\\', 'Widget' => '0_Widget'],
            array_column($items, 'sortText', 'label'),
            'matching child namespaces are nodes, and matching symbols of the requested kinds sort ahead of them',
        );
    }

    public function testNavigationOffersOnlyClassLikesThatResolveAndPassTheFilter(): void
    {
        $resolver = self::resolver(new NameContext('App'), phantoms: ['Lib\Phantom']);
        $resolver->method('isInstantiable')->willReturnCallback(
            static fn (ClasslikeName $name): bool => !self::isOneOf($name, ['Lib\Abstraction']),
        );
        $children = ['Lib' => new NamespaceContents(symbols: [
            new CatalogSymbol('Lib\Concrete', NameKind::ClassLike),
            new CatalogSymbol('Lib\Phantom', NameKind::ClassLike),
            new CatalogSymbol('Lib\Abstraction', NameKind::ClassLike),
        ])];

        $items = self::candidates(new NameContext('App'), children: $children, resolver: $resolver)
            ->find(self::requestAfter('$x = new \Lib\\'), [NameKind::ClassLike], ClassCandidateFilter::Instantiable);

        self::assertSame(
            ['Concrete'],
            self::labels($items),
            'a listed name with no class-like behind it, or one the position rejects, is not offered',
        );
    }

    public function testSmallChildNamespaceIsInlinedOneLevel(): void
    {
        $children = [
            'Lib' => new NamespaceContents(childNamespaces: ['Lib\Wire', 'Lib\Void']),
            'Lib\Wire' => new NamespaceContents(
                childNamespaces: ['Lib\Wire\Deep'],
                symbols: [
                    new CatalogSymbol('Lib\Wire\Cable', NameKind::ClassLike),
                    new CatalogSymbol('Lib\Wire\splice', NameKind::Function_),
                ],
            ),
        ];

        $items = self::candidates(new NameContext('App'), children: $children)
            ->find(self::requestAfter('$x = \Lib\\'), [NameKind::ClassLike], ClassCandidateFilter::Any);

        self::assertSame(
            [
                'Wire\Deep\\' => 'Wire\Deep',
                'Wire\Cable' => 'Wire\Cable',
                'Void\\' => 'Void',
            ],
            array_column($items, 'filterText', 'label'),
            'a few members are offered qualified by the segment; an empty namespace stays a node',
        );
    }

    public function testQualifiedPrefixStartsFromAnImportOrTheCurrentNamespace(): void
    {
        $children = [
            'Psr\Http' => new NamespaceContents(symbols: [new CatalogSymbol('Psr\Http\Client', NameKind::ClassLike)]),
            'App\Model' => new NamespaceContents(symbols: [
                new CatalogSymbol('App\Model\Customer', NameKind::ClassLike),
            ]),
        ];
        $context = new NameContext('App', classImports: ['Http' => 'Psr\Http']);
        $candidates = self::candidates($context, children: $children);

        self::assertSame(
            ['Client' => 'Psr\Http\Client'],
            array_column(
                $candidates->find(self::requestAfter('new Http\Cl'), [NameKind::ClassLike], ClassCandidateFilter::Any),
                'detail',
                'label',
            ),
            'a leading segment bound by an import navigates from the imported namespace',
        );
        self::assertSame(
            ['Customer' => 'App\Model\Customer'],
            array_column(
                $candidates->find(self::requestAfter('new Model\Cu'), [NameKind::ClassLike], ClassCandidateFilter::Any),
                'detail',
                'label',
            ),
            'any other leading segment navigates from the current namespace',
        );
    }

    public function testBarePrefixDescendsIntoAMatchingNamespace(): void
    {
        $children = [
            'App' => new NamespaceContents(childNamespaces: ['App\Model']),
            'App\Model' => new NamespaceContents(symbols: [
                new CatalogSymbol('App\Model\Customer', NameKind::ClassLike),
            ]),
            'Lib\Http' => self::largeNamespace('Lib\Http'),
        ];
        $context = new NameContext('App', classImports: ['Http' => 'Lib\Http', 'Hammer' => 'Lib\Hammer']);

        $candidates = self::candidates($context, children: $children);

        self::assertSame(
            ['Model\Customer'],
            self::labels(
                $candidates->find(self::requestAfter('$x = Mod'), [NameKind::ClassLike], ClassCandidateFilter::Any),
            ),
            'a child namespace of the current one is navigable without an import',
        );
        self::assertSame(
            ['Http', 'Hammer', 'Http\\'],
            self::labels(
                $candidates->find(self::requestAfter('$x = H'), [NameKind::ClassLike], ClassCandidateFilter::Any),
            ),
            'every import is offered by its alias, and one naming a namespace is also a node to descend into',
        );
    }

    public function testUseStatementNavigatesClassLikesFromTheGlobalNamespace(): void
    {
        $children = ['Lib' => new NamespaceContents(symbols: [
            new CatalogSymbol('Lib\Widget', NameKind::ClassLike),
            new CatalogSymbol('Lib\wrap', NameKind::Function_),
        ])];

        $items = self::candidates(new NameContext('App'), children: $children)->forUseStatement('\Lib\W', 1, 10);

        self::assertSame(
            ['Widget'],
            self::labels($items),
            'an import names class-likes from the global namespace, a leading \\ or not',
        );
    }

    /**
     * @param list<string> $search class-like search hits
     * @param list<string> $functionSearch function search hits
     * @param array<string, NamespaceContents> $children keyed by namespace path
     */
    private static function candidates(
        NameContext $context,
        array $search = [],
        array $functionSearch = [],
        array $children = [],
        ?CodeResolverInterface $resolver = null,
        SessionCapabilities $capabilities = new SessionCapabilities(),
    ): SymbolCandidates {
        return new SymbolCandidates(
            self::symbolSource($search, $functionSearch, $children),
            $resolver ?? self::resolver($context),
            self::capabilities($capabilities),
        );
    }

    /**
     * @param list<string> $search
     * @param list<string> $functionSearch
     * @param array<string, NamespaceContents> $children
     */
    private static function symbolSource(
        array $search = [],
        array $functionSearch = [],
        array $children = [],
    ): SymbolSourceInterface&Stub {
        $symbols = self::createStub(SymbolSourceInterface::class);
        $symbols->method('search')->willReturnCallback(
            static fn (string $prefix, NameKind $kind): array => array_map(
                static fn (string $fqn): Symbol => new Symbol(
                    NamespaceName::shortNameOf($fqn),
                    $fqn,
                    SymbolKind::Class_,
                    new Location('file:///f.php', 0, 0, 0, 0),
                ),
                match ($kind) {
                    NameKind::ClassLike => $search,
                    NameKind::Function_ => $functionSearch,
                    NameKind::Constant => [],
                },
            ),
        );
        $symbols->method('childrenOf')->willReturnCallback(
            static fn (NamespaceName $namespace): NamespaceContents
                => $children[$namespace->path] ?? new NamespaceContents(),
        );

        return $symbols;
    }

    /**
     * @param list<string> $phantoms listed names with no class-like behind them
     */
    private static function resolver(
        NameContext $context,
        array $phantoms = [],
    ): CodeResolverInterface&Stub {
        $resolver = self::createStub(CodeResolverInterface::class);
        $resolver->method('getNameContext')->willReturn($context);
        $resolver->method('isClassLike')->willReturnCallback(
            static fn (ClasslikeName $name): bool => !self::isOneOf($name, $phantoms),
        );

        return $resolver;
    }

    private static function capabilities(
        SessionCapabilities $capabilities = new SessionCapabilities(),
    ): SessionCapabilitiesProviderInterface {
        $provider = self::createStub(SessionCapabilitiesProviderInterface::class);
        $provider->method('getSessionCapabilities')->willReturn($capabilities);

        return $provider;
    }

    /**
     * More members than are inlined, so the namespace is offered as a node.
     */
    private static function largeNamespace(string $namespace): NamespaceContents
    {
        return new NamespaceContents(symbols: array_map(
            static fn (int $i): CatalogSymbol => new CatalogSymbol("{$namespace}\\Member{$i}", NameKind::ClassLike),
            range(1, 6),
        ));
    }

    /**
     * @param list<string> $fullyQualifiedNames
     */
    private static function isOneOf(ClasslikeName $name, array $fullyQualifiedNames): bool
    {
        return in_array($name->qualifiedName->fullyQualifiedName(), $fullyQualifiedNames, true);
    }

    private static function requestAfter(string $line): CompletionRequest
    {
        return new CompletionRequest(new TextDocument('file:///t.php', 'php', 0, "<?php\n{$line}"), 1, strlen($line));
    }

    /**
     * @param list<array{label: string}> $items
     * @return list<string>
     */
    private static function labels(array $items): array
    {
        return array_column($items, 'label');
    }
}
