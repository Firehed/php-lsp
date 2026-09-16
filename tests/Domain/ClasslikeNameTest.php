<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClasslikeName::class)]
class ClasslikeNameTest extends TestCase
{
    public function testShortNameWithNamespace(): void
    {
        $cn = new ClasslikeName(ClasslikeName::class);
        self::assertSame('ClasslikeName', $cn->shortName());
    }

    public function testShortNameWithoutNamespace(): void
    {
        $cn = new ClasslikeName(\stdClass::class);
        self::assertSame('stdClass', $cn->shortName());
    }

    public function testNamespaceWithNamespace(): void
    {
        $cn = new ClasslikeName(ClasslikeName::class);
        self::assertSame('Firehed\\PhpLsp\\Domain', $cn->namespace());
    }

    public function testNamespaceWithoutNamespace(): void
    {
        $cn = new ClasslikeName(\stdClass::class);
        self::assertNull($cn->namespace());
    }

    public function testEqualsTrue(): void
    {
        $a = new ClasslikeName(ClasslikeName::class);
        $b = new ClasslikeName(ClasslikeName::class);
        self::assertTrue($a->equals($b));
    }

    public function testEqualsFalse(): void
    {
        $a = new ClasslikeName(ClasslikeName::class);
        $b = new ClasslikeName(ClassKind::class);
        self::assertFalse($a->equals($b));
    }

    public function testEqualsCaseInsensitive(): void
    {
        $a = new ClasslikeName(ClasslikeName::class);
        /** @var class-string $lowercased */
        $lowercased = 'firehed\\phplsp\\domain\\classlikename';
        $b = new ClasslikeName($lowercased);
        self::assertTrue($a->equals($b));
    }

    public function testEqualsIgnoresALeadingSeparator(): void
    {
        $a = new ClasslikeName(ClasslikeName::class);
        /** @var class-string $leadingSeparator */
        $leadingSeparator = '\\' . ClasslikeName::class;
        $b = new ClasslikeName($leadingSeparator);
        self::assertTrue($a->equals($b), 'A leading separator is spelling, not identity');
    }

    public function testEqualsFalseAgainstDifferentTypeKind(): void
    {
        $a = new ClasslikeName(\stdClass::class);
        $b = new PrimitiveType('object');
        self::assertFalse($a->equals($b));
    }

    public function testEqualsComparesTypeArguments(): void
    {
        $a = new ClasslikeName(\ArrayIterator::class, [new ClasslikeName(\stdClass::class)]);
        $b = new ClasslikeName(\ArrayIterator::class, [new ClasslikeName(\stdClass::class)]);
        self::assertTrue($a->equals($b));
    }

    public function testEqualsFalseWhenTypeArgumentsDiffer(): void
    {
        $a = new ClasslikeName(\ArrayIterator::class, [new ClasslikeName(\stdClass::class)]);
        $b = new ClasslikeName(\ArrayIterator::class, [new ClasslikeName(\Iterator::class)]);
        self::assertFalse($a->equals($b));
    }

    public function testEqualsFalseWhenTypeArgumentCountsDiffer(): void
    {
        $a = new ClasslikeName(\ArrayIterator::class, [new ClasslikeName(\stdClass::class)]);
        $b = new ClasslikeName(\ArrayIterator::class);
        self::assertFalse($a->equals($b));
    }

    public function testFormatReturnsFqn(): void
    {
        $cn = new ClasslikeName(ClasslikeName::class);
        self::assertSame(ClasslikeName::class, $cn->format());
    }

    public function testGetResolvableClasslikeNamesReturnsItself(): void
    {
        $cn = new ClasslikeName(\stdClass::class);
        $classNames = $cn->getResolvableClasslikeNames();
        self::assertCount(1, $classNames);
        self::assertSame($cn, $classNames[0]);
    }

    public function testIsNullableReturnsFalse(): void
    {
        $cn = new ClasslikeName(\stdClass::class);
        self::assertFalse($cn->isNullable());
    }

    public function testResolveLateBoundReturnsSelf(): void
    {
        $cn = new ClasslikeName(\stdClass::class);
        self::assertSame($cn, $cn->resolveLateBound(\ArrayIterator::class));
    }

    public function testValueTypeIsNullWhenNoTypeArguments(): void
    {
        $cn = new ClasslikeName(\stdClass::class);
        self::assertNull($cn->valueType());
    }

    public function testValueTypeIsFirstTypeArgument(): void
    {
        $value = new ClasslikeName(\stdClass::class);
        $cn = new ClasslikeName(\ArrayIterator::class, [$value]);
        self::assertSame($value, $cn->valueType());
    }
}
