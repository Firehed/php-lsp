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
            new ClassName(\Iterator::class),
            new ClassName(\Countable::class),
        ]);
        self::assertSame('Iterator|Countable', $type->format());
    }

    public function testFormatNullableAsQuestionMark(): void
    {
        $type = new UnionType([
            new ClassName(\stdClass::class),
            new PrimitiveType('null'),
        ]);
        self::assertSame('?stdClass', $type->format());
    }

    public function testFormatNullableWithNullFirst(): void
    {
        $type = new UnionType([
            new PrimitiveType('null'),
            new ClassName(\stdClass::class),
        ]);
        self::assertSame('?stdClass', $type->format());
    }

    public function testFormatWrapsIntersectionInParentheses(): void
    {
        $type = new UnionType([
            new IntersectionType([
                new ClassName(\Iterator::class),
                new ClassName(\Countable::class),
            ]),
            new PrimitiveType('null'),
        ]);
        self::assertSame('(Iterator&Countable)|null', $type->format());
    }

    public function testGetResolvableClassNamesCollectsFromAllMembers(): void
    {
        $type = new UnionType([
            new ClassName(\Iterator::class),
            new PrimitiveType('string'),
            new ClassName(\Countable::class),
        ]);
        $classNames = $type->getResolvableClassNames();
        self::assertCount(2, $classNames);
        self::assertSame(\Iterator::class, $classNames[0]->fqn);
        self::assertSame(\Countable::class, $classNames[1]->fqn);
    }

    public function testGetResolvableClassNamesFromNestedTypes(): void
    {
        $type = new UnionType([
            new IntersectionType([
                new ClassName(\Iterator::class),
                new ClassName(\Countable::class),
            ]),
            new ClassName(\Traversable::class),
        ]);
        $classNames = $type->getResolvableClassNames();
        self::assertCount(3, $classNames);
        self::assertSame(\Iterator::class, $classNames[0]->fqn);
        self::assertSame(\Countable::class, $classNames[1]->fqn);
        self::assertSame(\Traversable::class, $classNames[2]->fqn);
    }

    public function testIsNullableWithNullMember(): void
    {
        $type = new UnionType([
            new ClassName(\stdClass::class),
            new PrimitiveType('null'),
        ]);
        self::assertTrue($type->isNullable());
    }

    public function testIsNullableWithoutNull(): void
    {
        $type = new UnionType([
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
        $type = new UnionType($members);

        self::assertSame($members, $type->getMembers());
    }
}
