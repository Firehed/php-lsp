<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Index\Location;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Index\SymbolKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymbolIndex::class)]
class SymbolIndexTest extends TestCase
{
    public function testAddAndFind(): void
    {
        $index = new SymbolIndex();
        $location = new Location('file:///test.php', 0, 0, 0, 10);
        $symbol = new Symbol('MyClass', 'App\\MyClass', SymbolKind::Class_, $location);

        $index->add($symbol);

        $found = $index->findByFqn('App\\MyClass');
        self::assertNotNull($found);
        self::assertSame('MyClass', $found->name);
    }

    public function testFindReturnsNullForUnknown(): void
    {
        $index = new SymbolIndex();

        self::assertNull($index->findByFqn('Unknown\\Class'));
    }

    public function testInNamespaceReturnsSymbolsDeclaredThere(): void
    {
        $index = new SymbolIndex();
        $location = new Location('file:///test.php', 0, 0, 0, 10);
        $index->add(new Symbol('Thing', 'App\\Thing', SymbolKind::Class_, $location));
        $index->add(new Symbol('Deep', 'App\\Sub\\Deep', SymbolKind::Class_, $location));

        $fqns = array_map(
            static fn(Symbol $symbol): string => $symbol->fullyQualifiedName,
            $index->inNamespace('App'),
        );

        self::assertSame(
            ['App\\Thing'],
            $fqns,
            'Only symbols declared directly in the namespace, not those in a child of it',
        );
    }

    public function testInNamespaceIsCaseInsensitive(): void
    {
        $index = new SymbolIndex();
        $location = new Location('file:///test.php', 0, 0, 0, 10);
        $index->add(new Symbol('Thing', 'App\\Thing', SymbolKind::Class_, $location));

        self::assertCount(1, $index->inNamespace('app'), 'PHP namespaces are case-insensitive');
    }

    public function testNamespacesListsEachNamespaceOnceInRealCasing(): void
    {
        $index = new SymbolIndex();
        $location = new Location('file:///test.php', 0, 0, 0, 10);
        $index->add(new Symbol('Thing', 'App\\Model\\Thing', SymbolKind::Class_, $location));
        $index->add(new Symbol('Other', 'App\\Model\\Other', SymbolKind::Class_, $location));
        $index->add(new Symbol('globalHelper', 'globalHelper', SymbolKind::Function_, $location));

        self::assertSame(
            ['App\\Model', ''],
            $index->namespaces(),
            'One entry per namespace that has symbols, spelled as declared; child namespaces are derived elsewhere',
        );
    }

    public function testClearByUri(): void
    {
        $index = new SymbolIndex();
        $location1 = new Location('file:///a.php', 0, 0, 0, 10);
        $location2 = new Location('file:///b.php', 0, 0, 0, 10);

        $index->add(new Symbol('ClassA', 'ClassA', SymbolKind::Class_, $location1));
        $index->add(new Symbol('ClassB', 'ClassB', SymbolKind::Class_, $location2));

        $index->clearByUri('file:///a.php');

        self::assertNull($index->findByFqn('ClassA'));
        self::assertNotNull($index->findByFqn('ClassB'));
    }

    public function testClearByUriRemovesFromTheNamespaceIndex(): void
    {
        $index = new SymbolIndex();
        $index->add(new Symbol('Thing', 'App\\Thing', SymbolKind::Class_, new Location('file:///a.php', 0, 0, 0, 10)));
        $index->add(new Symbol('Other', 'App\\Other', SymbolKind::Class_, new Location('file:///b.php', 0, 0, 0, 10)));

        $index->clearByUri('file:///a.php');

        $fqns = array_map(
            static fn(Symbol $symbol): string => $symbol->fullyQualifiedName,
            $index->inNamespace('App'),
        );

        self::assertSame(
            ['App\\Other'],
            $fqns,
            'A removed symbol is gone from the namespace index; the ones sharing its namespace remain',
        );
        self::assertSame(['App'], $index->namespaces(), 'The namespace still has a symbol, so it stays');
    }

    public function testClearByUriDropsANamespaceLeftEmpty(): void
    {
        $index = new SymbolIndex();
        $index->add(new Symbol('Only', 'App\\Only', SymbolKind::Class_, new Location('file:///a.php', 0, 0, 0, 10)));

        $index->clearByUri('file:///a.php');

        self::assertSame([], $index->namespaces(), 'A namespace with no symbols left is not reported');
        self::assertSame([], $index->inNamespace('App'), 'Nor does it hold symbols');
    }

    public function testFindByName(): void
    {
        $index = new SymbolIndex();
        $location = new Location('file:///test.php', 0, 0, 0, 10);

        $index->add(new Symbol('MyClass', 'App\\MyClass', SymbolKind::Class_, $location));
        $index->add(new Symbol('MyClass', 'Other\\MyClass', SymbolKind::Class_, $location));

        $found = $index->findByName('MyClass');

        self::assertCount(2, $found);
    }
}
