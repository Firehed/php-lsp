<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Closure;
use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Completion\CompletionItemFormatter;
use Firehed\PhpLsp\Completion\CompletionItemKind;
use Firehed\PhpLsp\Completion\SymbolCandidates;
use Firehed\PhpLsp\Domain\CatalogSymbol;
use Firehed\PhpLsp\Domain\ClassCandidateFilter;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceContents;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\PrefixMatcher;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Protocol\Range;
use Firehed\PhpLsp\Resolution\NameContext;
use Firehed\PhpLsp\Resolution\NameQuery;
use Firehed\PhpLsp\Resolution\ResolvedSymbolPresenter;
use Firehed\PhpLsp\Resolution\SymbolCapabilitiesInterface;
use Firehed\PhpLsp\Tests\BuildsSymbolInfoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How each symbol is written comes from ReferenceResolver, whose rules
 * ReferenceResolverTest owns; these tests cover which candidates are offered.
 */
#[CoversClass(SymbolCandidates::class)]
final class SymbolCandidatesTest extends TestCase
{
    use BuildsCompletionInputsTrait;
    use BuildsSymbolInfoTrait;

    public function testSearchHitIsOfferedByItsReferenceRankedByReach(): void
    {
        $context = new NameContext('App');
        $items = self::candidates(search: ['App\Widget', 'App\Gadget'])
            ->find(
                self::requestAfter('$x = Wid'),
                new NameQuery([NameKind::ClassLike], ClassCandidateFilter::Any, $context),
                'Wid',
            );

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
            'the typed prefix is searched, and the reference replaces it, sorted by how near the symbol is',
        );
    }

    public function testUnreachableSearchHitIsDroppedBeforeTheFilterRuns(): void
    {
        $context = new NameContext('Elsewhere');
        $asked = [];
        $items = self::candidates(
            search: ['App\Widget'],
            isInstantiable: static function (ClasslikeName $name) use (&$asked): bool {
                $asked[] = $name;
                return true;
            },
        )->find(
            self::requestAfter('$x = Wid'),
            new NameQuery([NameKind::ClassLike], ClassCandidateFilter::Instantiable, $context),
            'Wid',
        );

        self::assertSame([], $items, 'a class-like that needs an import or a leading \\ is not offered bare');
        self::assertSame([], $asked, 'the cheap reachability check runs before the filter resolves the class');
    }

    public function testImportsOfEveryKindAreOfferedByTheirAlias(): void
    {
        $context = new NameContext(
            'App',
            classImports: ['Gadget' => 'Lib\Device'],
            functionImports: ['gather' => 'Lib\collect'],
            constantImports: ['GAUGE' => 'Lib\METER'],
        );
        $range = Range::onLine(1, 5, 7)->toArray();

        self::assertSame(
            [
                [
                    'label' => 'Gadget',
                    'kind' => CompletionItemKind::Class_->value,
                    'detail' => 'Lib\Device',
                    'filterText' => 'Gadget',
                    'textEdit' => ['range' => $range, 'newText' => 'Gadget'],
                ],
                [
                    'label' => 'GAUGE',
                    'kind' => CompletionItemKind::Constant->value,
                    'detail' => 'Lib\METER',
                    'filterText' => 'GAUGE',
                    'textEdit' => ['range' => $range, 'newText' => 'GAUGE'],
                ],
                [
                    'label' => 'gather',
                    'kind' => CompletionItemKind::Function->value,
                    'detail' => 'Lib\collect',
                    'filterText' => 'gather',
                    'textEdit' => ['range' => $range, 'newText' => 'gather'],
                ],
            ],
            self::candidates()
                ->find(
                    self::requestAfter('$x = Ga'),
                    new NameQuery(NameKind::cases(), ClassCandidateFilter::Any, $context),
                    'Ga',
                ),
            'each kind\'s import is offered by the alias it binds',
        );
    }

    public function testCurrentNamespaceSymbolIsOfferedWithoutASearchHit(): void
    {
        $context = new NameContext('App');
        $children = ['App' => new NamespaceContents(symbols: [
            new CatalogSymbol('App\Widget', NameKind::ClassLike),
            new CatalogSymbol('App\Gizmo', NameKind::ClassLike),
            new CatalogSymbol('App\widget_count', NameKind::Function_),
        ])];

        self::assertSame(
            ['Widget'],
            self::labels(
                self::candidates(children: $children)
                    ->find(
                        self::requestAfter('$x = Wid'),
                        new NameQuery([NameKind::ClassLike], ClassCandidateFilter::Any, $context),
                        'Wid',
                    ),
            ),
            'a symbol declared in the current namespace is offered when it matches and is of a requested kind',
        );
    }

    public function testGlobalNamespaceListingIsLeftToSearch(): void
    {
        $context = new NameContext('');
        $children = ['' => new NamespaceContents(symbols: [new CatalogSymbol('Widget', NameKind::ClassLike)])];

        self::assertSame(
            [],
            self::candidates(children: $children)
                ->find(
                    self::requestAfter('$x = Wid'),
                    new NameQuery([NameKind::ClassLike], ClassCandidateFilter::Any, $context),
                    'Wid',
                ),
            'outside a namespace, only search offers global symbols',
        );
    }

    public function testFilterAppliesToEverySource(): void
    {
        $accepted = ['App\WidgetFound', 'Lib\WidgetImported', 'App\WidgetDeclared'];
        $context = new NameContext('App', classImports: [
            'WidgetImported' => 'Lib\WidgetImported',
            'WidgetRejectedImport' => 'Lib\WidgetRejectedImport',
        ]);
        $children = ['App' => new NamespaceContents(symbols: [
            new CatalogSymbol('App\WidgetDeclared', NameKind::ClassLike),
            new CatalogSymbol('App\WidgetRejectedDeclared', NameKind::ClassLike),
        ])];

        $items = self::candidates(
            search: ['App\WidgetFound', 'App\WidgetRejectedFound'],
            children: $children,
            isInstantiable: static fn (ClasslikeName $name): bool => self::isOneOf($name, $accepted),
        )->find(
            self::requestAfter('$x = Wid'),
            new NameQuery([NameKind::ClassLike], ClassCandidateFilter::Instantiable, $context),
            'Wid',
        );

        self::assertSame(
            ['WidgetFound', 'WidgetImported', 'WidgetDeclared'],
            self::labels($items),
            'search hits, imports, and current-namespace symbols all pass the position\'s filter',
        );
    }

    /**
     * @return iterable<string, array{ClassCandidateFilter, list<string>}>
     */
    public static function filterPredicates(): iterable
    {
        yield 'any' => [ClassCandidateFilter::Any, []];
        yield 'instantiable' => [ClassCandidateFilter::Instantiable, ['isInstantiable']];
        yield 'type hint' => [ClassCandidateFilter::TypeHint, ['isValidTypeHint']];
        yield 'interface' => [ClassCandidateFilter::Interface_, ['isInterface']];
        yield 'extendable class' => [ClassCandidateFilter::ExtendableClass, ['isExtendableClass']];
        yield 'throwable' => [ClassCandidateFilter::Throwable, ['isThrowable']];
        yield 'attribute' => [ClassCandidateFilter::Attribute, ['isAttribute']];
        yield 'trait' => [ClassCandidateFilter::Trait_, ['isTrait']];
    }

    /**
     * @param list<string> $predicates
     */
    #[DataProvider('filterPredicates')]
    public function testEachFilterAdmitsWhatItsPredicateAccepts(ClassCandidateFilter $filter, array $predicates): void
    {
        // Every predicate not named answers false, so a filter asking any other leaves the class-like out.
        $context = new NameContext('');
        $candidates = self::candidates(
            search: ['Widget'],
            accepting: $predicates,
        );

        self::assertSame(
            ['Widget'],
            self::labels($candidates->find(
                self::requestAfter('$x = Wid'),
                new NameQuery([NameKind::ClassLike], $filter, $context),
                'Wid',
            )),
            'the filter asks its own predicate',
        );
    }

    public function testSymbolReachedSeveralWaysIsOfferedOnce(): void
    {
        $children = ['App' => new NamespaceContents(symbols: [new CatalogSymbol('App\Widget', NameKind::ClassLike)])];
        $context = new NameContext('App', classImports: ['Wireless' => 'Lib\Wireless']);

        $items = self::candidates(search: ['App\Widget', 'Lib\Wireless'], children: $children)
            ->find(
                self::requestAfter('$x = Wi'),
                new NameQuery([NameKind::ClassLike], ClassCandidateFilter::Any, $context),
                'Wi',
            );

        self::assertSame(
            ['Widget' => '0_Widget', 'Wireless' => '1_Wireless'],
            array_column($items, 'sortText', 'label'),
            'the search hits are offered, and the import and namespace listing of the same symbols are not',
        );
    }

    public function testNameSharedAcrossKindsIsOfferedOnceAsTheFirstRequestedKind(): void
    {
        $context = new NameContext('App');
        $candidates = self::candidates(search: ['App\Thing'], functionSearch: ['App\Thing']);
        $request = self::requestAfter('$x = Thi');

        self::assertSame(
            [CompletionItemKind::Class_->value],
            array_column(
                $candidates->find(
                    $request,
                    new NameQuery([NameKind::ClassLike, NameKind::Function_], ClassCandidateFilter::Any, $context),
                    'Thi',
                ),
                'kind',
            ),
            'asked for class-likes first, the class is offered',
        );
        self::assertSame(
            [CompletionItemKind::Function->value],
            array_column(
                $candidates->find(
                    $request,
                    new NameQuery([NameKind::Function_, NameKind::ClassLike], ClassCandidateFilter::Any, $context),
                    'Thi',
                ),
                'kind',
            ),
            'asked for functions first, the function is offered',
        );
    }

    public function testShadowedNamespaceFunctionYieldsToTheImport(): void
    {
        $context = new NameContext('App', functionImports: ['calculateSum' => 'str_contains']);
        $children = ['App' => new NamespaceContents(symbols: [
            new CatalogSymbol('App\calculateSum', NameKind::Function_),
        ])];

        $items = self::candidates(
            functionSearch: ['App\calculateSum', 'App\calculateProduct'],
            children: $children,
        )->find(
            self::requestAfter('$x = calc'),
            new NameQuery([NameKind::Function_], ClassCandidateFilter::Any, $context),
            'calc',
        );

        self::assertSame(
            ['calculateProduct' => 'App\calculateProduct', 'calculateSum' => 'str_contains'],
            array_column($items, 'detail', 'label'),
            'an import of the same short name hides the namespace function, so the name means the import',
        );
    }

    public function testFunctionShowsItsSignature(): void
    {
        $context = new NameContext('App');
        $items = self::candidates(
            functionSearch: ['App\count_widgets'],
            function: self::functionInfo(QualifiedName::fromFullyQualified('App\count_widgets')),
        )->find(
            self::requestAfter('$x = count_'),
            new NameQuery([NameKind::Function_], ClassCandidateFilter::Any, $context),
            'count_',
        );

        self::assertSame(
            ['function count_widgets()'],
            array_column($items, 'detail'),
            'a function shows its signature rather than its name',
        );
    }

    public function testCurrentNamespaceFunctionRanksAboveAGlobalOne(): void
    {
        $context = new NameContext('App');
        $items = self::candidates(functionSearch: ['App\calc_widgets', 'calc_total'])
            ->find(
                self::requestAfter('$x = calc'),
                new NameQuery([NameKind::Function_], ClassCandidateFilter::Any, $context),
                'calc',
            );

        self::assertSame(
            ['calc_widgets' => '0_calc_widgets', 'calc_total' => '3_calc_total'],
            array_column($items, 'sortText', 'label'),
            'a function in the current namespace sorts ahead of a global one, such as a built-in',
        );
    }

    public function testCallableSnippetFollowsTheClient(): void
    {
        $context = new NameContext('App');
        $items = self::candidates(
            functionSearch: ['App\count_widgets'],
            capabilities: new SessionCapabilities(snippetSupport: true),
        )->find(
            self::requestAfter('$x = count_'),
            new NameQuery([NameKind::Function_], ClassCandidateFilter::Any, $context),
            'count_',
        );

        self::assertSame(
            ['count_widgets($0)'],
            array_column(array_column($items, 'textEdit'), 'newText'),
            'a client that takes snippets gets the call parentheses',
        );
    }

    public function testAbsolutePrefixOffersMatchingContentsOfItsNamespace(): void
    {
        $context = new NameContext('App');
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

        $items = self::candidates(children: $children)
            ->find(
                self::requestAfter('$x = \Lib\W'),
                new NameQuery([NameKind::ClassLike], ClassCandidateFilter::Any, $context),
                '\Lib\W',
            );

        self::assertSame(
            ['Web\\' => '1_Web\\', 'Widget' => '0_Widget'],
            array_column($items, 'sortText', 'label'),
            'matching child namespaces are nodes, and matching symbols of the requested kinds sort ahead of them',
        );
    }

    public function testNavigationOffersOnlyClassLikesThatResolveAndPassTheFilter(): void
    {
        $context = new NameContext('App');
        $children = ['Lib' => new NamespaceContents(symbols: [
            new CatalogSymbol('Lib\Concrete', NameKind::ClassLike),
            new CatalogSymbol('Lib\Phantom', NameKind::ClassLike),
            new CatalogSymbol('Lib\Abstraction', NameKind::ClassLike),
        ])];

        $items = self::candidates(
            children: $children,
            phantoms: ['Lib\Phantom'],
            isInstantiable: static fn (ClasslikeName $name): bool => !self::isOneOf($name, ['Lib\Abstraction']),
        )->find(
            self::requestAfter('$x = new \Lib\\'),
            new NameQuery([NameKind::ClassLike], ClassCandidateFilter::Instantiable, $context),
            '\\Lib\\',
        );

        self::assertSame(
            ['Concrete'],
            self::labels($items),
            'a listed name with no class-like behind it, or one the position rejects, is not offered',
        );
    }

    public function testSmallChildNamespaceIsInlinedOneLevel(): void
    {
        $context = new NameContext('App');
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

        $items = self::candidates(children: $children)
            ->find(
                self::requestAfter('$x = \Lib\\'),
                new NameQuery([NameKind::ClassLike], ClassCandidateFilter::Any, $context),
                '\\Lib\\',
            );

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
            'App\Parent' => new NamespaceContents(symbols: [
                new CatalogSymbol('App\Parent\Child', NameKind::ClassLike),
            ]),
        ];
        $context = new NameContext('App', classImports: ['Http' => 'Psr\Http']);
        $candidates = self::candidates(children: $children);

        self::assertSame(
            ['Client' => 'Psr\Http\Client'],
            array_column(
                $candidates->find(
                    self::requestAfter('new Http\Cl'),
                    new NameQuery([NameKind::ClassLike], ClassCandidateFilter::Any, $context),
                    'Http\Cl',
                ),
                'detail',
                'label',
            ),
            'a leading segment bound by an import navigates from the imported namespace',
        );
        self::assertSame(
            ['Client' => 'Psr\Http\Client'],
            array_column(
                $candidates->find(
                    self::requestAfter('new http\Cl'),
                    new NameQuery([NameKind::ClassLike], ClassCandidateFilter::Any, $context),
                    'http\Cl',
                ),
                'detail',
                'label',
            ),
            'an import alias matches regardless of case, as PHP resolves it',
        );
        self::assertSame(
            ['Child' => 'App\Parent\Child'],
            array_column(
                $candidates->find(
                    self::requestAfter('new Parent\Ch'),
                    new NameQuery([NameKind::ClassLike], ClassCandidateFilter::Any, $context),
                    'Parent\Ch',
                ),
                'detail',
                'label',
            ),
            'a leading segment spelled like a special class name is still an ordinary namespace segment',
        );
        self::assertSame(
            ['Customer' => 'App\Model\Customer'],
            array_column(
                $candidates->find(
                    self::requestAfter('new Model\Cu'),
                    new NameQuery([NameKind::ClassLike], ClassCandidateFilter::Any, $context),
                    'Model\Cu',
                ),
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

        $candidates = self::candidates(children: $children);

        self::assertSame(
            ['Model\Customer'],
            self::labels(
                $candidates->find(
                    self::requestAfter('$x = Mod'),
                    new NameQuery([NameKind::ClassLike], ClassCandidateFilter::Any, $context),
                    'Mod',
                ),
            ),
            'a child namespace of the current one is navigable without an import',
        );
        self::assertSame(
            ['Http', 'Hammer', 'Http\\'],
            self::labels(
                $candidates->find(
                    self::requestAfter('$x = H'),
                    new NameQuery([NameKind::ClassLike], ClassCandidateFilter::Any, $context),
                    'H',
                ),
            ),
            'every import is offered by its alias, and one naming a namespace is also a node to descend into',
        );
    }

    public function testUseStatementNavigatesClassLikesFromTheGlobalNamespace(): void
    {
        $context = new NameContext('App');
        $children = ['Lib' => new NamespaceContents(symbols: [
            new CatalogSymbol('Lib\Widget', NameKind::ClassLike),
            new CatalogSymbol('Lib\wrap', NameKind::Function_),
        ])];
        $candidates = self::candidates(children: $children);
        $import = new NameQuery([NameKind::ClassLike], ClassCandidateFilter::Any, $context, absolute: true);

        self::assertSame(
            ['Widget'],
            self::labels($candidates->find(self::requestAfter('use Lib\W'), $import, 'Lib\W')),
            'an import names class-likes from the global namespace, not the current one',
        );
        self::assertSame(
            ['Widget'],
            self::labels($candidates->find(self::requestAfter('use \Lib\W'), $import, '\Lib\W')),
            'a leading \\ names the same place',
        );
    }

    /**
     * @param list<string> $search class-like search hits
     * @param list<string> $functionSearch function search hits
     * @param array<string, NamespaceContents> $children keyed by namespace path
     * @param list<string> $phantoms listed names with no class-like behind them
     * @param ?Closure(ClasslikeName): bool $isInstantiable
     * @param list<string> $accepting predicates that accept every class-like
     */
    private static function candidates(
        array $search = [],
        array $functionSearch = [],
        array $children = [],
        array $phantoms = [],
        ?Closure $isInstantiable = null,
        ?FunctionInfo $function = null,
        SessionCapabilities $capabilities = new SessionCapabilities(),
        array $accepting = [],
    ): SymbolCandidates {
        $symbols = self::createStub(SymbolSourceInterface::class);
        $symbols->method('search')->willReturnCallback(
            static fn (string $prefix, NameKind $kind): array => array_values(array_filter(
                array_map(QualifiedName::fromFullyQualified(...), match ($kind) {
                    NameKind::ClassLike => $search,
                    NameKind::Function_ => $functionSearch,
                    NameKind::Constant => [],
                }),
                static fn (QualifiedName $name): bool => PrefixMatcher::matches($name->shortName, $prefix),
            )),
        );
        $symbols->method('childrenOf')->willReturnCallback(
            static fn (NamespaceName $namespace): NamespaceContents
                => $children[$namespace->path] ?? new NamespaceContents(),
        );
        $symbols->method('lookupFunction')->willReturn($function);

        $resolver = self::createStub(SymbolCapabilitiesInterface::class);
        $resolver->method('isClassLike')->willReturnCallback(
            static fn (ClasslikeName $name): bool => !self::isOneOf($name, $phantoms),
        );
        if ($isInstantiable !== null) {
            $resolver->method('isInstantiable')->willReturnCallback($isInstantiable);
        }
        foreach ($accepting as $predicate) {
            $resolver->method($predicate)->willReturn(true);
        }

        return new SymbolCandidates(
            $symbols,
            $resolver,
            self::capabilitiesProvider($capabilities),
            new CompletionItemFormatter(new ResolvedSymbolPresenter()),
        );
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

    /**
     * @param list<array{label: string}> $items
     * @return list<string>
     */
    private static function labels(array $items): array
    {
        return array_column($items, 'label');
    }
}
