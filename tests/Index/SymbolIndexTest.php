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
