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
            new ClassName(\Iterator::class),
            new ClassName(\Countable::class),
        ]);
        self::assertSame('Iterator&Countable', $type->format());
    }

    public function testGetResolvableClassNamesCollectsFromAllMembers(): void
    {
        $type = new IntersectionType([
            new ClassName(\Iterator::class),
            new ClassName(\Countable::class),
        ]);
        $classNames = $type->getResolvableClassNames();
        self::assertCount(2, $classNames);
        self::assertSame(\Iterator::class, $classNames[0]->fqn);
        self::assertSame(\Countable::class, $classNames[1]->fqn);
    }

    public function testIsNullableReturnsFalse(): void
    {
        $type = new IntersectionType([
            new ClassName(\Iterator::class),
            new ClassName(\Countable::class),
        ]);
        self::assertFalse($type->isNullable());
    }

    public function testValueTypeAgreesWhenMembersAgree(): void
    {
        $value = new ClassName(\stdClass::class);
        $type = new IntersectionType([
            new ClassName(\ArrayIterator::class, [$value]),
            new ClassName(\IteratorAggregate::class, [$value]),
        ]);
        $valueType = $type->valueType();
        self::assertInstanceOf(ClassName::class, $valueType);
        self::assertSame(\stdClass::class, $valueType->fqn);
    }

    public function testValueTypeIsNullWhenMembersDisagree(): void
    {
        $type = new IntersectionType([
            new ClassName(\ArrayIterator::class, [new ClassName(\stdClass::class)]),
            new ClassName(\IteratorAggregate::class, [new ClassName(\Iterator::class)]),
        ]);
        self::assertNull($type->valueType());
    }

    public function testValueTypeIsNullWhenAnyMemberHasNone(): void
    {
        $type = new IntersectionType([
            new ClassName(\ArrayIterator::class, [new ClassName(\stdClass::class)]),
            new ClassName(\IteratorAggregate::class),
        ]);
        self::assertNull($type->valueType());
    }

    public function testResolveLateBoundResolvesMembers(): void
    {
        $type = new IntersectionType([
            new LateStaticType(LateBindingKeyword::Static, new ClassName(\Traversable::class)),
            new ClassName(\Countable::class),
        ]);

        $resolved = $type->resolveLateBound(\ArrayIterator::class);

        self::assertInstanceOf(IntersectionType::class, $resolved);
        self::assertSame('ArrayIterator&Countable', $resolved->format());
    }
}
