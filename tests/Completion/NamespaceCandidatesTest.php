<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Capability\SessionCapabilitiesProvider;
use Firehed\PhpLsp\Completion\ClassCandidateFilter;
use Firehed\PhpLsp\Completion\CompletionItemFactory;
use Firehed\PhpLsp\Completion\CompletionItemKind;
use Firehed\PhpLsp\Completion\NamespaceCandidates;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Resolution\CodeResolver;
use Firehed\PhpLsp\Resolution\NameContext;
use Firehed\PhpLsp\Resolution\NameKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NamespaceCandidates::class)]
#[CoversClass(CompletionItemFactory::class)]
class NamespaceCandidatesTest extends TestCase
{
    /**
     * A namespace-aware catalog: each namespace maps to its contents, and any
     * namespace not in the map is empty. Navigation peeks one level into a child
     * namespace to decide node-vs-inline, so children must resolve independently.
     *
     * @param array<string, NamespaceContents> $byNamespace
     */
    private static function catalog(array $byNamespace): NamespaceCatalog
    {
        return new class ($byNamespace) implements NamespaceCatalog {
            /** @param array<string, NamespaceContents> $byNamespace */
            public function __construct(private readonly array $byNamespace)
            {
            }

            public function childrenOf(string $namespace): NamespaceContents
            {
                return $this->byNamespace[$namespace] ?? new NamespaceContents([], []);
            }
        };
    }

    /**
     * More class-likes than the inline threshold, so a namespace holding them is
     * offered as a node rather than inlined.
     *
     * @return list<CatalogSymbol>
     */
    private static function manyClassLikes(string $namespace): array
    {
        return array_map(
            static fn(int $i): CatalogSymbol => new CatalogSymbol($namespace . '\\C' . $i, NameKind::ClassLike),
            range(1, 6),
        );
    }

    /**
     * A resolver for which every candidate is a real class-like, so tests of the
     * kind gate, prefix filter, and position filter are not masked by the
     * existence gate that drops directory-listing phantoms.
     */
    private static function classLikeResolver(): CodeResolver
    {
        $resolver = self::createStub(CodeResolver::class);
        $resolver->method('isClassLike')->willReturn(true);
        return $resolver;
    }

    /**
     * A capabilities provider resolved to the default UTF-16 encoding, so the
     * replace-range columns these tests assert are wire columns.
     */
    private static function utf16Capabilities(): SessionCapabilitiesProvider
    {
        $capabilities = self::createStub(SessionCapabilitiesProvider::class);
        $capabilities->method('getSessionCapabilities')->willReturn(new SessionCapabilities());
        return $capabilities;
    }

    public function testOffersChildNamespacesAsModuleNodes(): void
    {
        $candidates = new NamespaceCandidates(
            self::catalog(['Psr' => new NamespaceContents(['Psr\Http', 'Psr\Log'], [])]),
            self::createStub(CodeResolver::class),
            self::utf16Capabilities(),
        );

        $items = $candidates->find('Psr', '', 0, 0, ClassCandidateFilter::Any);

        $byLabel = array_column($items, 'detail', 'label');
        self::assertSame(
            'Psr\Http',
            $byLabel['Http\\'] ?? null,
            'A child namespace is a navigable node whose label is the next segment plus a separator',
        );
        self::assertSame('Psr\Log', $byLabel['Log\\'] ?? null, 'Each child of the namespace is offered');
        foreach ($items as $item) {
            self::assertSame(
                CompletionItemKind::Module->value,
                $item['kind'] ?? null,
                'A namespace is a Module, not a class',
            );
        }
    }

    public function testFiltersChildrenByTheSegmentPrefix(): void
    {
        $candidates = new NamespaceCandidates(
            self::catalog(['Psr' => new NamespaceContents(['Psr\Http', 'Psr\Log'], [])]),
            self::createStub(CodeResolver::class),
            self::utf16Capabilities(),
        );

        $items = $candidates->find('Psr', 'Ht', 0, 2, ClassCandidateFilter::Any);

        self::assertSame(
            ['Http\\'],
            array_column($items, 'label'),
            'Only the child whose next segment matches the typed prefix is offered',
        );
    }

    public function testNodeInsertsSegmentWithTrailingSlashViaTextEdit(): void
    {
        // The separator must be in the inserted text, not just the label: clients
        // display the text they insert (Vim/ale shows textEdit.newText, not label),
        // so a bare segment would render indistinguishably from a same-named class.
        $candidates = new NamespaceCandidates(
            self::catalog(['Psr' => new NamespaceContents(['Psr\Http'], [])]),
            self::createStub(CodeResolver::class),
            self::utf16Capabilities(),
        );

        $items = $candidates->find('Psr', 'Ht', 0, 2, ClassCandidateFilter::Any);

        self::assertCount(1, $items);
        self::assertSame(
            [
                'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 2]],
                'newText' => 'Http\\',
            ],
            $items[0]['textEdit'] ?? null,
            'A node inserts the next segment with its trailing separator, replacing the typed partial segment',
        );
    }

    public function testReplaceRangeMeasuresAMultibyteSegmentInCodeUnits(): void
    {
        $catalog = self::catalog(['App' => new NamespaceContents([], [
            new CatalogSymbol('App\Café', NameKind::ClassLike),
        ])]);
        $candidates = new NamespaceCandidates($catalog, self::classLikeResolver(), self::utf16Capabilities());

        // "Café" is four codepoints — four UTF-16 units — but five UTF-8 bytes; the
        // cursor sits at wire column 4 after typing it at the start of the line.
        $items = $candidates->find('App', 'Café', 0, 4, ClassCandidateFilter::Any);

        self::assertCount(1, $items);
        self::assertSame(
            ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 4]],
            $items[0]['textEdit']['range'] ?? null,
            'The replace range sizes the typed segment in code units, not bytes (RFC 1 §4.9)',
        );
    }

    public function testOffersClassLikesButNotOtherKinds(): void
    {
        $catalog = self::catalog(['App' => new NamespaceContents([], [
            new CatalogSymbol('App\Widget', NameKind::ClassLike),
            new CatalogSymbol('App\helper', NameKind::Function_),
        ])]);
        // Any accepts every class-like, so only the kind gate is exercised here.
        $candidates = new NamespaceCandidates($catalog, self::classLikeResolver(), self::utf16Capabilities());

        $labels = array_column($candidates->find('App', '', 0, 0, ClassCandidateFilter::Any), 'label');

        self::assertContains('Widget', $labels, 'A class-like is offered');
        self::assertNotContains('helper', $labels, 'A function is not a class-like and is not offered here');
    }

    public function testFiltersSymbolsByTheSegmentPrefix(): void
    {
        $catalog = self::catalog(['App' => new NamespaceContents([], [
            new CatalogSymbol('App\Widget', NameKind::ClassLike),
            new CatalogSymbol('App\Gadget', NameKind::ClassLike),
        ])]);
        $candidates = new NamespaceCandidates($catalog, self::classLikeResolver(), self::utf16Capabilities());

        $labels = array_column($candidates->find('App', 'Wi', 0, 2, ClassCandidateFilter::Any), 'label');

        self::assertSame(['Widget'], $labels, 'Only the class whose leaf matches the typed prefix is offered');
    }

    public function testExcludesClassLikesTheFilterRejects(): void
    {
        $catalog = self::catalog(['App' => new NamespaceContents([], [
            new CatalogSymbol('App\Contract', NameKind::ClassLike),
        ])]);
        // isClassLike is true (a real class-like) but isInstantiable is false,
        // standing in for an interface after `new`: the position filter rejects
        // it, via the same predicate the index and imports use.
        $candidates = new NamespaceCandidates($catalog, self::classLikeResolver(), self::utf16Capabilities());

        self::assertSame(
            [],
            $candidates->find('App', '', 0, 0, ClassCandidateFilter::Instantiable),
            'A class-like the position rejects is filtered out, via the shared filter predicate',
        );
    }

    public function testDropsSymbolsWithNoClassLikeBehindThem(): void
    {
        // The catalog reports every .php file as a coarse class-like without
        // parsing it, so a functions.php arrives as a phantom name. The existence
        // gate drops it even where the position (Any) accepts anything.
        $catalog = self::catalog(['App' => new NamespaceContents([], [
            new CatalogSymbol('App\functions', NameKind::ClassLike),
        ])]);
        $resolver = self::createStub(CodeResolver::class);
        $resolver->method('isClassLike')->willReturn(false);
        $candidates = new NamespaceCandidates($catalog, $resolver, self::utf16Capabilities());

        self::assertSame(
            [],
            $candidates->find('App', '', 0, 0, ClassCandidateFilter::Any),
            'A catalog phantom with no class-like behind the name is never offered',
        );
    }

    public function testInlinesSmallChildNamespaceAlongsideItsSameNamedClass(): void
    {
        // App\Env is both a class and a small namespace. The class and the
        // namespace's contents are offered directly; the Env\ node is omitted,
        // since stepping through a handful of entries is needless.
        $catalog = self::catalog([
            'App' => new NamespaceContents(['App\Env'], [new CatalogSymbol('App\Env', NameKind::ClassLike)]),
            'App\Env' => new NamespaceContents([], [new CatalogSymbol('App\Env\Repository', NameKind::ClassLike)]),
        ]);
        $candidates = new NamespaceCandidates($catalog, self::classLikeResolver(), self::utf16Capabilities());

        $labels = array_column($candidates->find('App', '', 0, 0, ClassCandidateFilter::Any), 'label');

        self::assertContains('Env', $labels, 'The same-named class is offered');
        self::assertContains('Env\Repository', $labels, 'A small namespace is inlined, qualified by its segment');
        self::assertNotContains('Env\\', $labels, 'The node is omitted when the namespace is inlined');
    }

    public function testInlinedEntryFiltersOnItsQualifiedReference(): void
    {
        // The user reaches an inlined entry by typing the parent segment (E ->
        // Env), so the entry must filter on the qualified reference, not its leaf,
        // or a client filtering locally would hide it.
        $catalog = self::catalog([
            'App' => new NamespaceContents(['App\Env'], []),
            'App\Env' => new NamespaceContents([], [new CatalogSymbol('App\Env\Repository', NameKind::ClassLike)]),
        ]);
        $candidates = new NamespaceCandidates($catalog, self::classLikeResolver(), self::utf16Capabilities());

        $byLabel = array_column($candidates->find('App', '', 0, 0, ClassCandidateFilter::Any), 'filterText', 'label');

        self::assertSame(
            'Env\Repository',
            $byLabel['Env\Repository'] ?? null,
            'An inlined entry filters on its qualified reference so typing the parent segment keeps it',
        );
    }

    public function testInlineSkipsSymbolsWithNoClassLikeBehindThem(): void
    {
        // A small namespace is inlined, but one of its entries is a phantom (a
        // functions.php). The inline path drops it, offering only the real class.
        $catalog = self::catalog([
            'App' => new NamespaceContents(['App\Env'], []),
            'App\Env' => new NamespaceContents([], [
                new CatalogSymbol('App\Env\Repository', NameKind::ClassLike),
                new CatalogSymbol('App\Env\functions', NameKind::ClassLike),
            ]),
        ]);
        $resolver = self::createStub(CodeResolver::class);
        $resolver->method('isClassLike')->willReturnCallback(
            static fn(ClassName $name): bool => str_ends_with($name->fqn, 'Repository'),
        );
        $candidates = new NamespaceCandidates($catalog, $resolver, self::utf16Capabilities());

        $labels = array_column($candidates->find('App', '', 0, 0, ClassCandidateFilter::Any), 'label');

        self::assertContains('Env\Repository', $labels, 'A real inlined class-like is offered');
        self::assertNotContains('Env\functions', $labels, 'A phantom inlined symbol is skipped');
    }

    public function testInlinesNamespaceAtExactlyTheThreshold(): void
    {
        // Five members (the threshold) still inlines; a sixth would make it a node.
        $five = array_map(
            static fn(int $i): CatalogSymbol => new CatalogSymbol("App\\Five\\C{$i}", NameKind::ClassLike),
            range(1, 5),
        );
        $catalog = self::catalog([
            'App' => new NamespaceContents(['App\Five'], []),
            'App\Five' => new NamespaceContents([], $five),
        ]);
        $candidates = new NamespaceCandidates($catalog, self::classLikeResolver(), self::utf16Capabilities());

        $labels = array_column($candidates->find('App', '', 0, 0, ClassCandidateFilter::Any), 'label');

        self::assertNotContains('Five\\', $labels, 'A namespace at exactly the threshold is inlined, not a node');
        self::assertContains('Five\C1', $labels, 'Its members are inlined, qualified by the segment');
    }

    public function testInlinesGrandchildNamespaceAsQualifiedNode(): void
    {
        // Inlining is one level: a small namespace exposes a grandchild namespace
        // as a qualified node to step into, not by recursing into it.
        $catalog = self::catalog([
            'App' => new NamespaceContents(['App\Small'], []),
            'App\Small' => new NamespaceContents(['App\Small\Deep'], []),
        ]);
        $candidates = new NamespaceCandidates($catalog, self::classLikeResolver(), self::utf16Capabilities());

        $labels = array_column($candidates->find('App', '', 0, 0, ClassCandidateFilter::Any), 'label');

        self::assertContains('Small\Deep\\', $labels, 'An inlined namespace exposes a grandchild as a qualified node');
        self::assertNotContains('Small\\', $labels, 'The inlined namespace itself is not offered as a node');
    }

    public function testDescendInlinesSmallTargetsAndNodesLargeOnes(): void
    {
        // A bare name reaches its candidates through the same offerChildNamespace as
        // absolute navigation, so a small one inlines and a large one is a node.
        $catalog = self::catalog([
            'App' => new NamespaceContents(['App\Small'], []),
            'App\Small' => new NamespaceContents([], [new CatalogSymbol('App\Small\Thing', NameKind::ClassLike)]),
            'Vendor\Big' => new NamespaceContents([], self::manyClassLikes('Vendor\Big')),
            'Vendor\Plain' => new NamespaceContents([], []),
        ]);
        $candidates = new NamespaceCandidates($catalog, self::classLikeResolver(), self::utf16Capabilities());
        $context = new NameContext('App', ['Big' => 'Vendor\Big', 'Plain' => 'Vendor\Plain']);

        $labels = array_column($candidates->descend($context, '', 0, 0, ClassCandidateFilter::Any), 'label');

        self::assertContains(
            'Small\Thing',
            $labels,
            'A small current-namespace child inlines, like absolute navigation',
        );
        self::assertContains('Big\\', $labels, 'A large import is a node');
        self::assertNotContains('Plain\\', $labels, 'An import that is not a namespace is skipped');
    }

    public function testDescendFiltersByPrefix(): void
    {
        $catalog = self::catalog([
            'App' => new NamespaceContents([], []),
            'Vendor\Mapping' => new NamespaceContents([], self::manyClassLikes('Vendor\Mapping')),
            'Vendor\Other' => new NamespaceContents([], self::manyClassLikes('Vendor\Other')),
        ]);
        $candidates = new NamespaceCandidates($catalog, self::classLikeResolver(), self::utf16Capabilities());
        $context = new NameContext('App', ['Mapping' => 'Vendor\Mapping', 'Other' => 'Vendor\Other']);

        $labels = array_column($candidates->descend($context, 'Map', 0, 3, ClassCandidateFilter::Any), 'label');

        self::assertSame(['Mapping\\'], $labels, 'Only imports whose name begins with the prefix are offered');
    }

    public function testNavigateWalksAbsoluteNames(): void
    {
        $catalog = self::catalog([
            'Psr\Http' => new NamespaceContents([], [
                new CatalogSymbol('Psr\Http\Message', NameKind::ClassLike),
            ]),
        ]);
        $candidates = new NamespaceCandidates($catalog, self::classLikeResolver(), self::utf16Capabilities());

        $items = $candidates->navigate('\Psr\Http\M', new NameContext(''), 0, 12, ClassCandidateFilter::Any);

        self::assertContains(
            'Message',
            array_column($items, 'label'),
            'An absolute name walks from the global namespace',
        );
    }

    public function testNavigateDescendsForBareNames(): void
    {
        $catalog = self::catalog([
            'App' => new NamespaceContents([], []),
            'Vendor\Pkg' => new NamespaceContents([], self::manyClassLikes('Vendor\Pkg')),
        ]);
        $candidates = new NamespaceCandidates($catalog, self::classLikeResolver(), self::utf16Capabilities());
        $context = new NameContext('App', ['Pkg' => 'Vendor\Pkg']);

        $items = $candidates->navigate('Pk', $context, 0, 2, ClassCandidateFilter::Any);

        self::assertContains('Pkg\\', array_column($items, 'label'), 'A bare name descends into an imported namespace');
    }

    public function testNavigateResolvesQualifiedNamesThroughImports(): void
    {
        $catalog = self::catalog([
            'Vendor\Pkg' => new NamespaceContents([], [
                new CatalogSymbol('Vendor\Pkg\Thing', NameKind::ClassLike),
            ]),
        ]);
        $candidates = new NamespaceCandidates($catalog, self::classLikeResolver(), self::utf16Capabilities());
        $context = new NameContext('App', ['Pkg' => 'Vendor\Pkg']);

        $items = $candidates->navigate('Pkg\T', $context, 0, 5, ClassCandidateFilter::Any);

        self::assertContains('Thing', array_column($items, 'label'), 'A qualified name resolves through the imports');
    }

    public function testNavigateResolvesQualifiedNamesRelativeToTheCurrentNamespace(): void
    {
        $catalog = self::catalog([
            'App\Sub' => new NamespaceContents([], [
                new CatalogSymbol('App\Sub\Thing', NameKind::ClassLike),
            ]),
        ]);
        $candidates = new NamespaceCandidates($catalog, self::classLikeResolver(), self::utf16Capabilities());

        $items = $candidates->navigate('Sub\T', new NameContext('App'), 0, 5, ClassCandidateFilter::Any);

        self::assertContains(
            'Thing',
            array_column($items, 'label'),
            'A qualified name falls back to the current namespace',
        );
    }

    public function testUseStatementWalksQualifiedNameFromGlobal(): void
    {
        $catalog = self::catalog([
            'Psr\Http' => new NamespaceContents([], [
                new CatalogSymbol('Psr\Http\Message', NameKind::ClassLike),
            ]),
        ]);
        $candidates = new NamespaceCandidates($catalog, self::classLikeResolver(), self::utf16Capabilities());

        $items = $candidates->useStatement('Psr\Http\M', 0, 10, ClassCandidateFilter::Any);

        self::assertContains(
            'Message',
            array_column($items, 'label'),
            'A `use` name walks from the global namespace, offering the leaf at the final segment',
        );
    }

    public function testUseStatementIgnoresOptionalLeadingBackslash(): void
    {
        $catalog = self::catalog([
            'Psr\Http' => new NamespaceContents([], [
                new CatalogSymbol('Psr\Http\Message', NameKind::ClassLike),
            ]),
        ]);
        $candidates = new NamespaceCandidates($catalog, self::classLikeResolver(), self::utf16Capabilities());

        $items = $candidates->useStatement('\Psr\Http\M', 0, 11, ClassCandidateFilter::Any);

        self::assertContains(
            'Message',
            array_column($items, 'label'),
            'A leading `\` is accepted and ignored: `use \Foo` names the same symbol as `use Foo`',
        );
    }

    public function testUseStatementNavigatesFirstSegmentFromGlobal(): void
    {
        // A single typed segment (no separator) walks the global namespace's
        // children, so a root namespace is offered as a node to step into.
        $catalog = self::catalog([
            '' => new NamespaceContents(['Psr'], []),
            'Psr' => new NamespaceContents([], self::manyClassLikes('Psr')),
        ]);
        $candidates = new NamespaceCandidates($catalog, self::classLikeResolver(), self::utf16Capabilities());

        $labels = array_column($candidates->useStatement('Ps', 0, 2, ClassCandidateFilter::Any), 'label');

        self::assertContains(
            'Psr\\',
            $labels,
            'A bare first segment navigates the global namespace, not the current one',
        );
    }

    public function testRanksSymbolsAboveNamespaceNodes(): void
    {
        $catalog = self::catalog([
            'App' => new NamespaceContents(['App\Big'], [new CatalogSymbol('App\Widget', NameKind::ClassLike)]),
            'App\Big' => new NamespaceContents([], self::manyClassLikes('App\Big')),
        ]);
        $candidates = new NamespaceCandidates($catalog, self::classLikeResolver(), self::utf16Capabilities());

        $byLabel = array_column($candidates->find('App', '', 0, 0, ClassCandidateFilter::Any), 'sortText', 'label');

        self::assertArrayHasKey('Widget', $byLabel, 'The class symbol is offered');
        self::assertArrayHasKey('Big\\', $byLabel, 'The namespace node is offered');
        self::assertLessThan(
            $byLabel['Big\\'],
            $byLabel['Widget'],
            'A directly-insertable symbol ranks above a namespace node',
        );
    }

    public function testOffersNodeAndClassWhenNamespaceExceedsInlineThreshold(): void
    {
        // A namespace with more than five members is offered as a node to step
        // through; its same-named class is still offered alongside.
        $catalog = self::catalog([
            'App\Entities' => new NamespaceContents(
                ['App\Entities\Env'],
                [new CatalogSymbol('App\Entities\Env', NameKind::ClassLike)],
            ),
            'App\Entities\Env' => new NamespaceContents([], self::manyClassLikes('App\Entities\Env')),
        ]);
        $candidates = new NamespaceCandidates($catalog, self::classLikeResolver(), self::utf16Capabilities());

        $labels = array_column($candidates->find('App\Entities', '', 0, 0, ClassCandidateFilter::Any), 'label');

        self::assertContains('Env', $labels, 'The same-named class is offered');
        self::assertContains('Env\\', $labels, 'A namespace above the threshold is a node to navigate into');
        self::assertNotContains(
            'Env\C1',
            $labels,
            'A namespace above the threshold is not inlined',
        );
    }
}
