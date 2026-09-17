<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClasslikeType::class)]
class ClasslikeTypeTest extends TestCase
{
    public function testFormatReturnsFqn(): void
    {
        $type = new ClasslikeType(new ClasslikeName(\stdClass::class));
        self::assertSame(\stdClass::class, $type->format(), 'format() surfaces the FQN of the paired identifier');
    }

    public function testGetResolvableClasslikeNamesReturnsTheName(): void
    {
        $name = new ClasslikeName(\stdClass::class);
        $type = new ClasslikeType($name);
        self::assertSame([$name], $type->getResolvableClasslikeNames(), 'a class-like type resolves to its own identifier');
    }

    public function testIsNullableReturnsFalse(): void
    {
        $type = new ClasslikeType(new ClasslikeName(\stdClass::class));
        self::assertFalse($type->isNullable(), 'a bare class-like type is never nullable; nullability composes via UnionType');
    }

    public function testResolveLateBoundReturnsSelf(): void
    {
        $type = new ClasslikeType(new ClasslikeName(\stdClass::class));
        self::assertSame($type, $type->resolveLateBound(\ArrayIterator::class), 'a concrete class-like type has nothing to resolve');
    }

    public function testValueTypeIsNullWhenNoTypeArguments(): void
    {
        $type = new ClasslikeType(new ClasslikeName(\stdClass::class));
        self::assertNull($type->valueType(), 'no generics means no value type');
    }

    public function testValueTypeIsFirstTypeArgument(): void
    {
        $value = new ClasslikeType(new ClasslikeName(\stdClass::class));
        $type = new ClasslikeType(new ClasslikeName(\ArrayIterator::class), [$value]);
        self::assertSame($value, $type->valueType(), 'the first type argument is the value type by convention');
    }

    public function testEqualsTrue(): void
    {
        $a = new ClasslikeType(new ClasslikeName(\stdClass::class));
        $b = new ClasslikeType(new ClasslikeName(\stdClass::class));
        self::assertTrue($a->equals($b), 'two class-like types with the same identifier are equal');
    }

    public function testEqualsFalseAgainstDifferentName(): void
    {
        $a = new ClasslikeType(new ClasslikeName(\stdClass::class));
        $b = new ClasslikeType(new ClasslikeName(\ArrayIterator::class));
        self::assertFalse($a->equals($b), 'different underlying identifiers are unequal');
    }

    public function testEqualsCaseInsensitive(): void
    {
        $a = new ClasslikeType(new ClasslikeName(\stdClass::class));
        /** @var class-string $lowered */
        $lowered = 'stdclass';
        $b = new ClasslikeType(new ClasslikeName($lowered));
        self::assertTrue($a->equals($b), 'identifier equality is case-insensitive; the type wraps it');
    }

    public function testEqualsFalseAgainstDifferentTypeKind(): void
    {
        $a = new ClasslikeType(new ClasslikeName(\stdClass::class));
        $b = new PrimitiveType('object');
        self::assertFalse($a->equals($b), 'a class-like type is never equal to a primitive');
    }

    public function testEqualsComparesTypeArguments(): void
    {
        $a = new ClasslikeType(
            new ClasslikeName(\ArrayIterator::class),
            [new ClasslikeType(new ClasslikeName(\stdClass::class))],
        );
        $b = new ClasslikeType(
            new ClasslikeName(\ArrayIterator::class),
            [new ClasslikeType(new ClasslikeName(\stdClass::class))],
        );
        self::assertTrue($a->equals($b), 'matching type arguments are equal');
    }

    public function testEqualsFalseWhenTypeArgumentsDiffer(): void
    {
        $a = new ClasslikeType(
            new ClasslikeName(\ArrayIterator::class),
            [new ClasslikeType(new ClasslikeName(\stdClass::class))],
        );
        $b = new ClasslikeType(
            new ClasslikeName(\ArrayIterator::class),
            [new ClasslikeType(new ClasslikeName(\Iterator::class))],
        );
        self::assertFalse($a->equals($b), 'divergent type arguments produce inequality');
    }

    public function testEqualsFalseWhenTypeArgumentCountsDiffer(): void
    {
        $a = new ClasslikeType(
            new ClasslikeName(\ArrayIterator::class),
            [new ClasslikeType(new ClasslikeName(\stdClass::class))],
        );
        $b = new ClasslikeType(new ClasslikeName(\ArrayIterator::class));
        self::assertFalse($a->equals($b), 'arity differences produce inequality');
    }
}
