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
        self::assertSame('string', PrimitiveType::String->format());
    }

    public function testGetResolvableClasslikeNamesReturnsEmptyList(): void
    {
        self::assertSame([], PrimitiveType::Int->getResolvableClasslikeNames());
    }

    public function testNullIsNullable(): void
    {
        self::assertTrue(PrimitiveType::Null->isNullable());
    }

    public function testNonNullIsNotNullable(): void
    {
        self::assertFalse(PrimitiveType::String->isNullable());
    }

    public function testResolveLateBoundReturnsSelf(): void
    {
        $called = ClasslikeName::fromFullyQualified(\ArrayIterator::class);
        self::assertSame(
            PrimitiveType::String,
            PrimitiveType::String->resolveLateBound(new LateBinding($called, $called, null)),
        );
    }

    public function testEqualsSameName(): void
    {
        self::assertTrue(PrimitiveType::Array->equals(PrimitiveType::Array));
    }

    public function testEqualsFalseWhenNameDiffers(): void
    {
        self::assertFalse(PrimitiveType::String->equals(PrimitiveType::Int));
    }

    public function testEqualsFalseAgainstDifferentTypeKind(): void
    {
        $other = new ClasslikeType(ClasslikeName::fromFullyQualified(\stdClass::class));
        self::assertFalse(PrimitiveType::Object->equals($other), 'a primitive is never equal to a class-like type');
    }
}
