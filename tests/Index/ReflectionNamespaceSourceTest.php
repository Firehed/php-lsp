<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\ReflectionNamespaceSource;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Index\SymbolKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReflectionNamespaceSource::class)]
#[CoversClass(CatalogSymbol::class)]
#[CoversClass(NamespaceContents::class)]
class ReflectionNamespaceSourceTest extends TestCase
{
    private ReflectionNamespaceSource $source;

    protected function setUp(): void
    {
        $this->source = new ReflectionNamespaceSource();
    }

    public function testGlobalNamespaceContainsBuiltinClassesFunctionsAndConstants(): void
    {
        $contents = $this->source->childrenOf('');

        self::assertContains(
            'Exception',
            self::symbolNames($contents, NameKind::ClassLike),
            'Built-in classes are in the global namespace and must be discoverable',
        );
        self::assertContains(
            'strlen',
            self::symbolNames($contents, NameKind::Function_),
            'Built-in functions must be discoverable',
        );
        self::assertContains(
            'PHP_EOL',
            self::symbolNames($contents, NameKind::Constant),
            'Built-in constants must be discoverable',
        );
    }

    public function testBuiltinInterfacesAreDiscoverable(): void
    {
        $contents = $this->source->childrenOf('');

        self::assertContains(
            'SessionHandlerInterface',
            self::symbolNames($contents, NameKind::ClassLike),
            'The interface from #308 that could never be offered before',
        );
    }

    public function testInternalSymbolsAreNotAssumedToBeGlobal(): void
    {
        $global = $this->source->childrenOf('');

        self::assertContains(
            'Random',
            $global->childNamespaces,
            'Random\ is an internal namespace, so the global namespace has a child',
        );
        self::assertNotContains(
            'Randomizer',
            self::symbolNames($global, NameKind::ClassLike),
            'Random\Randomizer is internal but namespaced; it does not belong to global',
        );

        self::assertContains(
            'Random\Randomizer',
            self::fqns($this->source->childrenOf('Random')),
            'An internal namespaced class is filed under its real namespace',
        );
    }

    public function testUserlandSymbolsAreExcluded(): void
    {
        $contents = $this->source->childrenOf('');

        self::assertNotContains(
            'Firehed',
            $contents->childNamespaces,
            'The language server\'s own classes are loaded in this process but are not built-ins',
        );
        self::assertNotContains(
            'PhpParser',
            $contents->childNamespaces,
            'Vendored dependencies of the server are not built-ins either',
        );
    }

    public function testUnknownNamespaceIsEmpty(): void
    {
        $contents = $this->source->childrenOf('No\Such\Namespace');

        self::assertSame([], $contents->childNamespaces, 'An unknown namespace has no children');
        self::assertSame([], $contents->symbols, 'An unknown namespace has no symbols');
    }

    public function testSearchByPrefixFindsBuiltinFunctions(): void
    {
        $results = $this->source->searchByPrefix('str_contains', NameKind::Function_);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains(
            'str_contains',
            $fqns,
            'a built-in function whose short name starts with the prefix must be found',
        );
    }

    public function testSearchByPrefixFindsBuiltinConstants(): void
    {
        $results = $this->source->searchByPrefix('PHP_INT_M', NameKind::Constant);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains(
            'PHP_INT_MAX',
            $fqns,
            'a built-in constant whose short name starts with the prefix must be found',
        );
    }

    public function testSearchByPrefixIsCaseInsensitive(): void
    {
        $results = $this->source->searchByPrefix('STR_CONTAINS', NameKind::Function_);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains(
            'str_contains',
            $fqns,
            'prefix matching is case-insensitive because the user has not finished typing',
        );
    }

    public function testSearchByPrefixReturnsCorrectSymbolKind(): void
    {
        $results = $this->source->searchByPrefix('str_contains', NameKind::Function_);

        self::assertNotEmpty($results, 'the prefix must match at least one function');
        foreach ($results as $symbol) {
            self::assertSame(
                SymbolKind::Function_,
                $symbol->kind,
                'every symbol returned for a Function_ search must carry SymbolKind::Function_',
            );
        }
    }

    public function testSearchByPrefixDoesNotCrossKindBoundaries(): void
    {
        $results = $this->source->searchByPrefix('Array', NameKind::Function_);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertNotContains(
            'ArrayObject',
            $fqns,
            'a class-like must not appear in a function search',
        );
    }

    public function testSearchByPrefixReturnsEmptyForNoMatch(): void
    {
        self::assertSame(
            [],
            $this->source->searchByPrefix('zzNoMatch', NameKind::Function_),
            'a prefix that matches nothing must return an empty list',
        );
    }

    public function testSearchByPrefixExcludesUserlandFunctions(): void
    {
        require_once dirname(__DIR__) . '/Domain/Fixtures/documented_function.php';

        $results = $this->source->searchByPrefix('testDocumented', NameKind::Function_);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertNotContains(
            'testDocumentedFunction',
            $fqns,
            'a userland function loaded in the server process must not appear in built-in search',
        );
    }

    /**
     * @return list<string>
     */
    private static function symbolNames(NamespaceContents $contents, NameKind $kind): array
    {
        $names = [];
        foreach ($contents->symbols as $symbol) {
            if ($symbol->kind === $kind) {
                $names[] = $symbol->shortName();
            }
        }

        return $names;
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
