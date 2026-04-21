<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassName::class)]
class ClassNameTest extends TestCase
{
    public function testShortNameWithNamespace(): void
    {
        $cn = new ClassName('Vendor\\Package\\MyClass');
        self::assertSame('MyClass', $cn->shortName());
    }

    public function testShortNameWithoutNamespace(): void
    {
        $cn = new ClassName('MyClass');
        self::assertSame('MyClass', $cn->shortName());
    }

    public function testNamespaceWithNamespace(): void
    {
        $cn = new ClassName('Vendor\\Package\\MyClass');
        self::assertSame('Vendor\\Package', $cn->namespace());
    }

    public function testNamespaceWithoutNamespace(): void
    {
        $cn = new ClassName('MyClass');
        self::assertNull($cn->namespace());
    }

    public function testEqualsTrue(): void
    {
        $a = new ClassName('Foo\\Bar');
        $b = new ClassName('Foo\\Bar');
        self::assertTrue($a->equals($b));
    }

    public function testEqualsFalse(): void
    {
        $a = new ClassName('Foo\\Bar');
        $b = new ClassName('Foo\\Baz');
        self::assertFalse($a->equals($b));
    }

    public function testEqualsCaseInsensitive(): void
    {
        $a = new ClassName('Foo\\Bar');
        $b = new ClassName('FOO\\BAR');
        self::assertTrue($a->equals($b));
    }
}
