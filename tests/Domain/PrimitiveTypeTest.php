<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PrimitiveType::class)]
class PrimitiveTypeTest extends TestCase
{
    public function testFormatReturnsName(): void
    {
        $type = new PrimitiveType('string');
        self::assertSame('string', $type->format());
    }

    public function testGetResolvableClasslikeNamesReturnsEmptyList(): void
    {
        $type = new PrimitiveType('int');
        self::assertSame([], $type->getResolvableClasslikeNames());
    }

    public function testNullIsNullable(): void
    {
        $type = new PrimitiveType('null');
        self::assertTrue($type->isNullable());
    }

    public function testNonNullIsNotNullable(): void
    {
        $type = new PrimitiveType('string');
        self::assertFalse($type->isNullable());
    }

    public function testResolveLateBoundReturnsSelf(): void
    {
        $type = new PrimitiveType('string');
        self::assertSame($type, $type->resolveLateBound(\ArrayIterator::class));
    }

    public function testValueTypeIsNullWhenNoTypeArguments(): void
    {
        $type = new PrimitiveType('array');
        self::assertNull($type->valueType());
    }

    public function testValueTypeIsFirstTypeArgument(): void
    {
        $value = new ClasslikeType(new ClasslikeName(\stdClass::class));
        $type = new PrimitiveType('array', [$value]);
        self::assertSame($value, $type->valueType());
    }

    public function testEqualsSameNameAndArgs(): void
    {
        $a = new PrimitiveType('array', [new ClasslikeType(new ClasslikeName(\stdClass::class))]);
        $b = new PrimitiveType('array', [new ClasslikeType(new ClasslikeName(\stdClass::class))]);
        self::assertTrue($a->equals($b));
    }

    public function testEqualsFalseWhenNameDiffers(): void
    {
        $a = new PrimitiveType('string');
        $b = new PrimitiveType('int');
        self::assertFalse($a->equals($b));
    }

    public function testEqualsFalseWhenTypeArgumentsDiffer(): void
    {
        $a = new PrimitiveType('array', [new ClasslikeType(new ClasslikeName(\stdClass::class))]);
        $b = new PrimitiveType('array', [new ClasslikeType(new ClasslikeName(\Iterator::class))]);
        self::assertFalse($a->equals($b));
    }

    public function testEqualsFalseWhenTypeArgumentCountsDiffer(): void
    {
        $a = new PrimitiveType('array', [new ClasslikeType(new ClasslikeName(\stdClass::class))]);
        $b = new PrimitiveType('array');
        self::assertFalse($a->equals($b));
    }

    public function testEqualsFalseAgainstDifferentTypeKind(): void
    {
        $a = new PrimitiveType('object');
        $b = new ClasslikeType(new ClasslikeName(\stdClass::class));
        self::assertFalse($a->equals($b), 'a primitive is never equal to a class-like type');
    }
}
