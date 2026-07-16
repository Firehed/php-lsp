<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\ClassCandidateFilter;
use Firehed\PhpLsp\Completion\CompletionItemFactory;
use Firehed\PhpLsp\Completion\CompletionItemKind;
use Firehed\PhpLsp\Completion\NamespaceCandidates;
use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Resolution\CodeResolver;
use Firehed\PhpLsp\Resolution\NameKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NamespaceCandidates::class)]
#[CoversClass(CompletionItemFactory::class)]
class NamespaceCandidatesTest extends TestCase
{
    /**
     * @param list<string> $childNamespaces
     * @param list<CatalogSymbol> $symbols
     */
    private static function catalogWith(array $childNamespaces, array $symbols = []): NamespaceCatalog
    {
        return new class ($childNamespaces, $symbols) implements NamespaceCatalog {
            /**
             * @param list<string> $childNamespaces
             * @param list<CatalogSymbol> $symbols
             */
            public function __construct(
                private readonly array $childNamespaces,
                private readonly array $symbols,
            ) {
            }

            public function childrenOf(string $namespace): NamespaceContents
            {
                return new NamespaceContents($this->childNamespaces, $this->symbols);
            }
        };
    }

    public function testOffersChildNamespacesAsModuleNodes(): void
    {
        $candidates = new NamespaceCandidates(
            self::catalogWith(['Psr\Http', 'Psr\Log']),
            self::createStub(CodeResolver::class),
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
            self::catalogWith(['Psr\Http', 'Psr\Log']),
            self::createStub(CodeResolver::class),
        );

        $items = $candidates->find('Psr', 'Ht', 0, 2, ClassCandidateFilter::Any);

        self::assertSame(
            ['Http\\'],
            array_column($items, 'label'),
            'Only the child whose next segment matches the typed prefix is offered',
        );
    }

    public function testOffersClassLikesButNotOtherKinds(): void
    {
        $catalog = self::catalogWith([], [
            new CatalogSymbol('App\Widget', NameKind::ClassLike),
            new CatalogSymbol('App\helper', NameKind::Function_),
        ]);
        // Any accepts every class-like, so only the kind gate is exercised here.
        $candidates = new NamespaceCandidates($catalog, self::createStub(CodeResolver::class));

        $labels = array_column($candidates->find('App', '', 0, 0, ClassCandidateFilter::Any), 'label');

        self::assertContains('Widget', $labels, 'A class-like is offered');
        self::assertNotContains('helper', $labels, 'A function is not a class-like and is not offered here');
    }

    public function testFiltersSymbolsByTheSegmentPrefix(): void
    {
        $catalog = self::catalogWith([], [
            new CatalogSymbol('App\Widget', NameKind::ClassLike),
            new CatalogSymbol('App\Gadget', NameKind::ClassLike),
        ]);
        $candidates = new NamespaceCandidates($catalog, self::createStub(CodeResolver::class));

        $labels = array_column($candidates->find('App', 'Wi', 0, 2, ClassCandidateFilter::Any), 'label');

        self::assertSame(['Widget'], $labels, 'Only the class whose leaf matches the typed prefix is offered');
    }

    public function testExcludesClassLikesTheFilterRejects(): void
    {
        $catalog = self::catalogWith([], [new CatalogSymbol('App\Contract', NameKind::ClassLike)]);
        // A stub CodeResolver returns false for isInstantiable, standing in for an
        // interface after `new`. The same predicate the index and imports use.
        $candidates = new NamespaceCandidates($catalog, self::createStub(CodeResolver::class));

        self::assertSame(
            [],
            $candidates->find('App', '', 0, 0, ClassCandidateFilter::Instantiable),
            'A class-like the position rejects is filtered out, via the shared filter predicate',
        );
    }
}
