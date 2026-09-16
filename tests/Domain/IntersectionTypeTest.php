<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IntersectionType::class)]
#[CoversClass(LateStaticType::class)]
class IntersectionTypeTest extends TestCase
{
    public function testFormatJoinsWithAmpersand(): void
    {
        $type = new IntersectionType([
            new ClasslikeName(\Iterator::class),
            new ClasslikeName(\Countable::class),
        ]);
        self::assertSame('Iterator&Countable', $type->format());
    }

    public function testGetResolvableClasslikeNamesCollectsFromAllMembers(): void
    {
        $type = new IntersectionType([
            new ClasslikeName(\Iterator::class),
            new ClasslikeName(\Countable::class),
        ]);
        $classNames = $type->getResolvableClasslikeNames();
        self::assertCount(2, $classNames);
        self::assertSame(\Iterator::class, $classNames[0]->fqn);
        self::assertSame(\Countable::class, $classNames[1]->fqn);
    }

    public function testIsNullableReturnsFalse(): void
    {
        $type = new IntersectionType([
            new ClasslikeName(\Iterator::class),
            new ClasslikeName(\Countable::class),
        ]);
        self::assertFalse($type->isNullable());
    }

    public function testValueTypeAgreesWhenMembersAgree(): void
    {
        $value = new ClasslikeName(\stdClass::class);
        $type = new IntersectionType([
            new ClasslikeName(\ArrayIterator::class, [$value]),
            new ClasslikeName(\IteratorAggregate::class, [$value]),
        ]);
        $valueType = $type->valueType();
        self::assertInstanceOf(ClasslikeName::class, $valueType);
        self::assertSame(\stdClass::class, $valueType->fqn);
    }

    public function testValueTypeIsNullWhenMembersDisagree(): void
    {
        $type = new IntersectionType([
            new ClasslikeName(\ArrayIterator::class, [new ClasslikeName(\stdClass::class)]),
            new ClasslikeName(\IteratorAggregate::class, [new ClasslikeName(\Iterator::class)]),
        ]);
        self::assertNull($type->valueType());
    }

    public function testValueTypeIsNullWhenAnyMemberHasNone(): void
    {
        $type = new IntersectionType([
            new ClasslikeName(\ArrayIterator::class, [new ClasslikeName(\stdClass::class)]),
            new ClasslikeName(\IteratorAggregate::class),
        ]);
        self::assertNull($type->valueType());
    }

    public function testEqualsSameMembersInOrder(): void
    {
        $a = new IntersectionType([new ClasslikeName(\Iterator::class), new ClasslikeName(\Countable::class)]);
        $b = new IntersectionType([new ClasslikeName(\Iterator::class), new ClasslikeName(\Countable::class)]);
        self::assertTrue($a->equals($b));
    }

    public function testEqualsFalseWhenMemberOrderDiffers(): void
    {
        $a = new IntersectionType([new ClasslikeName(\Iterator::class), new ClasslikeName(\Countable::class)]);
        $b = new IntersectionType([new ClasslikeName(\Countable::class), new ClasslikeName(\Iterator::class)]);
        self::assertFalse($a->equals($b));
    }

    public function testEqualsFalseWhenMemberCountDiffers(): void
    {
        $a = new IntersectionType([new ClasslikeName(\Iterator::class), new ClasslikeName(\Countable::class)]);
        $b = new IntersectionType([new ClasslikeName(\Iterator::class)]);
        self::assertFalse($a->equals($b));
    }

    public function testEqualsFalseAgainstDifferentTypeKind(): void
    {
        $a = new IntersectionType([new ClasslikeName(\Iterator::class), new ClasslikeName(\Countable::class)]);
        $b = new UnionType([new ClasslikeName(\Iterator::class), new ClasslikeName(\Countable::class)]);
        self::assertFalse($a->equals($b));
    }

    public function testResolveLateBoundResolvesMembers(): void
    {
        $type = new IntersectionType([
            new LateStaticType(LateBindingKeyword::Static, new ClasslikeName(\Traversable::class)),
            new ClasslikeName(\Countable::class),
        ]);

        $resolved = $type->resolveLateBound(\ArrayIterator::class);

        self::assertInstanceOf(IntersectionType::class, $resolved);
        self::assertSame('ArrayIterator&Countable', $resolved->format());
    }
}
