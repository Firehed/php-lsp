<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\CompositeNamespaceCatalog;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Index\NamespaceContents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * One backend can reach a namespace's contents by more than one route — Composer's
 * autoload maps address most names by arithmetic, and the derived `autoload.files`
 * index covers what they structurally cannot. Enumeration must report their union,
 * or a symbol is reachable by lookup and invisible to completion (RFC 1 §4.2).
 */
#[CoversClass(CompositeNamespaceCatalog::class)]
final class CompositeNamespaceCatalogTest extends TestCase
{
    public function testChildrenOfMergesEveryCatalog(): void
    {
        $catalog = new CompositeNamespaceCatalog([
            self::catalogOf(['App\Http'], ['App\User']),
            self::catalogOf(['App\Cli'], ['App\helper']),
        ]);

        $contents = $catalog->childrenOf('App');

        self::assertSame(
            ['App\Http', 'App\Cli'],
            $contents->childNamespaces,
            'a child namespace reported by any route must be enumerated',
        );
        self::assertSame(
            ['App\User', 'App\helper'],
            self::fqns($contents),
            'a symbol reported by any route must be enumerated',
        );
    }

    public function testTheEarlierCatalogWinsANameReportedByBoth(): void
    {
        // The same class-like under its case rule, spelled differently: the
        // spelling distinguishes which catalog's report survived the merge.
        $first = new CatalogSymbol('App\Shared', NameKind::ClassLike);
        $second = new CatalogSymbol('APP\SHARED', NameKind::ClassLike);

        $contents = (new CompositeNamespaceCatalog([
            self::catalogOfSymbols([$first]),
            self::catalogOfSymbols([$second]),
        ]))->childrenOf('App');

        self::assertSame(
            [$first],
            $contents->symbols,
            'catalogs are passed in order of authority, so the earlier one settles a clash',
        );
    }

    public function testEveryCatalogIsAskedAboutTheSameNamespace(): void
    {
        $counting = new CountingNamespaceCatalog();

        $contents = (new CompositeNamespaceCatalog([$counting, $counting]))->childrenOf('Psr\Log');

        self::assertSame(2, $counting->calls, 'each catalog is consulted, not just the first to answer');
        self::assertSame(
            ['Psr\Log\Child'],
            $contents->childNamespaces,
            'the namespace asked for must be passed through unchanged',
        );
    }

    public function testACompositeOverNoCatalogsIsEmpty(): void
    {
        $contents = (new CompositeNamespaceCatalog([]))->childrenOf('App');

        self::assertSame([], $contents->childNamespaces, 'no route means no children');
        self::assertSame([], $contents->symbols, 'no route means no symbols');
    }

    /**
     * @param list<string> $childNamespaces
     * @param list<string> $symbolFqns
     */
    private static function catalogOf(array $childNamespaces, array $symbolFqns): NamespaceCatalog
    {
        return self::catalogReturning(new NamespaceContents(
            $childNamespaces,
            array_map(
                static fn(string $fqn): CatalogSymbol => new CatalogSymbol($fqn, NameKind::ClassLike),
                $symbolFqns,
            ),
        ));
    }

    /**
     * @param list<CatalogSymbol> $symbols
     */
    private static function catalogOfSymbols(array $symbols): NamespaceCatalog
    {
        return self::catalogReturning(new NamespaceContents([], $symbols));
    }

    private static function catalogReturning(NamespaceContents $contents): NamespaceCatalog
    {
        $catalog = self::createStub(NamespaceCatalog::class);
        $catalog->method('childrenOf')->willReturn($contents);

        return $catalog;
    }

    /**
     * @return list<string>
     */
    private static function fqns(NamespaceContents $contents): array
    {
        return array_map(
            static fn(CatalogSymbol $symbol): string => $symbol->fullyQualifiedName,
            $contents->symbols,
        );
    }
}
