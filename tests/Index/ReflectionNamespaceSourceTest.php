<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\ReflectionNamespaceSource;
use Firehed\PhpLsp\Resolution\NameKind;
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
