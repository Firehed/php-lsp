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
        $cn = new ClassName(ClassName::class);
        self::assertSame('ClassName', $cn->shortName());
    }

    public function testShortNameWithoutNamespace(): void
    {
        $cn = new ClassName(\stdClass::class);
        self::assertSame('stdClass', $cn->shortName());
    }

    public function testNamespaceWithNamespace(): void
    {
        $cn = new ClassName(ClassName::class);
        self::assertSame('Firehed\\PhpLsp\\Domain', $cn->namespace());
    }

    public function testNamespaceWithoutNamespace(): void
    {
        $cn = new ClassName(\stdClass::class);
        self::assertNull($cn->namespace());
    }

    public function testEqualsTrue(): void
    {
        $a = new ClassName(ClassName::class);
        $b = new ClassName(ClassName::class);
        self::assertTrue($a->equals($b));
    }

    public function testEqualsFalse(): void
    {
        $a = new ClassName(ClassName::class);
        $b = new ClassName(ClassKind::class);
        self::assertFalse($a->equals($b));
    }

    public function testEqualsCaseInsensitive(): void
    {
        $a = new ClassName(ClassName::class);
        /** @var class-string $lowercased */
        $lowercased = 'firehed\\phplsp\\domain\\classname';
        $b = new ClassName($lowercased);
        self::assertTrue($a->equals($b));
    }

    public function testFormatReturnsFqn(): void
    {
        $cn = new ClassName(ClassName::class);
        self::assertSame(ClassName::class, $cn->format());
    }

    public function testGetResolvableClassNamesReturnsItself(): void
    {
        $cn = new ClassName(\stdClass::class);
        $classNames = $cn->getResolvableClassNames();
        self::assertCount(1, $classNames);
        self::assertSame($cn, $classNames[0]);
    }

    public function testIsNullableReturnsFalse(): void
    {
        $cn = new ClassName(\stdClass::class);
        self::assertFalse($cn->isNullable());
    }

    public function testResolveLateBoundReturnsSelf(): void
    {
        $cn = new ClassName(\stdClass::class);
        self::assertSame($cn, $cn->resolveLateBound(\ArrayIterator::class));
    }
}
