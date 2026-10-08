<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IntersectionType::class)]
class IntersectionTypeTest extends TestCase
{
    /**
     * @param list<TypeInterface> $args
     */
    private static function classlike(string $fqn, array $args = []): ClasslikeType
    {
        /** @var class-string $fqn */
        return new ClasslikeType(ClasslikeName::fromFullyQualified($fqn), $args);
    }

    public function testFormatJoinsWithAmpersand(): void
    {
        $type = new IntersectionType([
            self::classlike(\Iterator::class),
            self::classlike(\Countable::class),
        ]);
        self::assertSame('Iterator&Countable', $type->format());
    }

    public function testGetResolvableClasslikeNamesCollectsFromAllMembers(): void
    {
        $type = new IntersectionType([
            self::classlike(\Iterator::class),
            self::classlike(\Countable::class),
        ]);
        $classNames = $type->getResolvableClasslikeNames();
        self::assertCount(2, $classNames);
        self::assertSame(\Iterator::class, $classNames[0]->qualifiedName->fullyQualifiedName());
        self::assertSame(\Countable::class, $classNames[1]->qualifiedName->fullyQualifiedName());
    }

    public function testIsNullableReturnsFalse(): void
    {
        $type = new IntersectionType([
            self::classlike(\Iterator::class),
            self::classlike(\Countable::class),
        ]);
        self::assertFalse($type->isNullable());
    }

    public function testEqualsSameMembersInOrder(): void
    {
        $a = new IntersectionType([self::classlike(\Iterator::class), self::classlike(\Countable::class)]);
        $b = new IntersectionType([self::classlike(\Iterator::class), self::classlike(\Countable::class)]);
        self::assertTrue($a->equals($b));
    }

    public function testEqualsFalseWhenMemberOrderDiffers(): void
    {
        $a = new IntersectionType([self::classlike(\Iterator::class), self::classlike(\Countable::class)]);
        $b = new IntersectionType([self::classlike(\Countable::class), self::classlike(\Iterator::class)]);
        self::assertFalse($a->equals($b));
    }

    public function testEqualsFalseWhenMemberCountDiffers(): void
    {
        $a = new IntersectionType([self::classlike(\Iterator::class), self::classlike(\Countable::class)]);
        $b = new IntersectionType([self::classlike(\Iterator::class)]);
        self::assertFalse($a->equals($b));
    }

    public function testEqualsFalseAgainstDifferentTypeKind(): void
    {
        $a = new IntersectionType([self::classlike(\Iterator::class), self::classlike(\Countable::class)]);
        $b = new UnionType([self::classlike(\Iterator::class), self::classlike(\Countable::class)]);
        self::assertFalse($a->equals($b));
    }

    public function testResolveLateBoundResolvesMembers(): void
    {
        $type = new IntersectionType([
            new LateStaticType(LateBindingKeyword::Static, ClasslikeName::fromFullyQualified(\Traversable::class)),
            self::classlike(\Countable::class),
        ]);

        $resolved = $type->resolveLateBound(\ArrayIterator::class);

        self::assertInstanceOf(IntersectionType::class, $resolved);
        self::assertSame('ArrayIterator&Countable', $resolved->format());
    }
}
