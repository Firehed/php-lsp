<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IntersectionType::class)]
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

    public function testGetMembersReturnsMembers(): void
    {
        $members = [
            new ClassName(\Iterator::class),
            new ClassName(\Countable::class),
        ];
        $type = new IntersectionType($members);

        self::assertSame($members, $type->getMembers());
    }

    public function testResolveLateBoundResolvesMembers(): void
    {
        $type = new IntersectionType([
            new ClassName(\Iterator::class),
            new ClassName(\Countable::class),
        ]);

        $resolved = $type->resolveLateBound(\ArrayIterator::class);

        self::assertInstanceOf(IntersectionType::class, $resolved);
        $members = $resolved->getMembers();
        self::assertCount(2, $members);
        self::assertInstanceOf(ClassName::class, $members[0]);
        self::assertSame(\Iterator::class, $members[0]->fqn);
    }
}
