<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnionType::class)]
class UnionTypeTest extends TestCase
{
    public function testFormatJoinsWithPipe(): void
    {
        $type = new UnionType([
            new ClasslikeType(new ClasslikeName(\Iterator::class)),
            new ClasslikeType(new ClasslikeName(\Countable::class)),
        ]);
        self::assertSame('Iterator|Countable', $type->format());
    }

    public function testFormatNullableAsQuestionMark(): void
    {
        $type = new UnionType([
            new ClasslikeType(new ClasslikeName(\stdClass::class)),
            new PrimitiveType('null'),
        ]);
        self::assertSame('?stdClass', $type->format());
    }

    public function testFormatNullableWithNullFirst(): void
    {
        $type = new UnionType([
            new PrimitiveType('null'),
            new ClasslikeType(new ClasslikeName(\stdClass::class)),
        ]);
        self::assertSame('?stdClass', $type->format());
    }

    public function testFormatWrapsIntersectionInParentheses(): void
    {
        $type = new UnionType([
            new IntersectionType([
                new ClasslikeType(new ClasslikeName(\Iterator::class)),
                new ClasslikeType(new ClasslikeName(\Countable::class)),
            ]),
            new PrimitiveType('null'),
        ]);
        self::assertSame('(Iterator&Countable)|null', $type->format());
    }

    public function testGetResolvableClasslikeNamesCollectsFromAllMembers(): void
    {
        $type = new UnionType([
            new ClasslikeType(new ClasslikeName(\Iterator::class)),
            new PrimitiveType('string'),
            new ClasslikeType(new ClasslikeName(\Countable::class)),
        ]);
        $classNames = $type->getResolvableClasslikeNames();
        self::assertCount(2, $classNames);
        self::assertSame(\Iterator::class, $classNames[0]->fqn);
        self::assertSame(\Countable::class, $classNames[1]->fqn);
    }

    public function testGetResolvableClasslikeNamesFromNestedTypes(): void
    {
        $type = new UnionType([
            new IntersectionType([
                new ClasslikeType(new ClasslikeName(\Iterator::class)),
                new ClasslikeType(new ClasslikeName(\Countable::class)),
            ]),
            new ClasslikeType(new ClasslikeName(\Traversable::class)),
        ]);
        $classNames = $type->getResolvableClasslikeNames();
        self::assertCount(3, $classNames);
        self::assertSame(\Iterator::class, $classNames[0]->fqn);
        self::assertSame(\Countable::class, $classNames[1]->fqn);
        self::assertSame(\Traversable::class, $classNames[2]->fqn);
    }

    public function testIsNullableWithNullMember(): void
    {
        $type = new UnionType([
            new ClasslikeType(new ClasslikeName(\stdClass::class)),
            new PrimitiveType('null'),
        ]);
        self::assertTrue($type->isNullable());
    }

    public function testIsNullableWithoutNull(): void
    {
        $type = new UnionType([
            new ClasslikeType(new ClasslikeName(\Iterator::class)),
            new ClasslikeType(new ClasslikeName(\Countable::class)),
        ]);
        self::assertFalse($type->isNullable());
    }

    public function testValueTypeAgreesWhenMembersAgree(): void
    {
        $value = new ClasslikeType(new ClasslikeName(\stdClass::class));
        $type = new UnionType([
            new ClasslikeType(new ClasslikeName(\ArrayIterator::class), [$value]),
            new PrimitiveType('array', [$value]),
        ]);
        $valueType = $type->valueType();
        self::assertInstanceOf(ClasslikeType::class, $valueType);
        self::assertSame(\stdClass::class, $valueType->name->fqn);
    }

    public function testValueTypeIsNullWhenMembersDisagree(): void
    {
        $stdClass = new ClasslikeType(new ClasslikeName(\stdClass::class));
        $type = new UnionType([
            new ClasslikeType(new ClasslikeName(\ArrayIterator::class), [$stdClass]),
            new PrimitiveType('array', [new PrimitiveType('int')]),
        ]);
        self::assertNull($type->valueType());
    }

    public function testValueTypeIsNullWhenAnyMemberHasNone(): void
    {
        $stdClass = new ClasslikeType(new ClasslikeName(\stdClass::class));
        $type = new UnionType([
            new ClasslikeType(new ClasslikeName(\ArrayIterator::class), [$stdClass]),
            new PrimitiveType('array'),
        ]);
        self::assertNull($type->valueType());
    }

    public function testEqualsSameMembersInOrder(): void
    {
        $a = new UnionType([new ClasslikeType(new ClasslikeName(\stdClass::class)), new PrimitiveType('null')]);
        $b = new UnionType([new ClasslikeType(new ClasslikeName(\stdClass::class)), new PrimitiveType('null')]);
        self::assertTrue($a->equals($b));
    }

    public function testEqualsFalseWhenMemberOrderDiffers(): void
    {
        $a = new UnionType([new ClasslikeType(new ClasslikeName(\stdClass::class)), new PrimitiveType('null')]);
        $b = new UnionType([new PrimitiveType('null'), new ClasslikeType(new ClasslikeName(\stdClass::class))]);
        self::assertFalse($a->equals($b));
    }

    public function testEqualsFalseWhenMemberCountDiffers(): void
    {
        $a = new UnionType([new ClasslikeType(new ClasslikeName(\stdClass::class)), new PrimitiveType('null')]);
        $b = new UnionType([new ClasslikeType(new ClasslikeName(\stdClass::class))]);
        self::assertFalse($a->equals($b));
    }

    public function testEqualsFalseAgainstDifferentTypeKind(): void
    {
        $a = new UnionType([new ClasslikeType(new ClasslikeName(\stdClass::class)), new PrimitiveType('null')]);
        $b = new IntersectionType([new ClasslikeType(new ClasslikeName(\stdClass::class)), new PrimitiveType('null')]);
        self::assertFalse($a->equals($b));
    }

    public function testResolveLateBoundResolvesMembers(): void
    {
        $type = new UnionType([
            new LateStaticType(LateBindingKeyword::Static, new ClasslikeName(\Traversable::class)),
            new PrimitiveType('null'),
        ]);

        $resolved = $type->resolveLateBound(\ArrayIterator::class);

        self::assertInstanceOf(UnionType::class, $resolved);
        self::assertSame('?ArrayIterator', $resolved->format());
        $classNames = $resolved->getResolvableClasslikeNames();
        self::assertCount(1, $classNames);
        self::assertSame(\ArrayIterator::class, $classNames[0]->fqn);
    }
}
