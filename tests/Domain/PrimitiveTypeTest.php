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

    public function testGetResolvableClassNamesReturnsEmptyList(): void
    {
        $type = new PrimitiveType('int');
        self::assertSame([], $type->getResolvableClassNames());
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
        $value = new ClassName(\stdClass::class);
        $type = new PrimitiveType('array', [$value]);
        self::assertSame($value, $type->valueType());
    }
}
