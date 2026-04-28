<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NamedType::class)]
#[CoversClass(UnionType::class)]
#[CoversClass(IntersectionType::class)]
class TypeTest extends TestCase
{
    public function testNamedTypeWithClassNameFormatsCorrectly(): void
    {
        $type = new NamedType('Foo\\Bar', isBuiltin: false);
        self::assertSame('Foo\\Bar', $type->format());
    }

    public function testNamedTypeWithClassNameReturnsClassName(): void
    {
        $type = new NamedType('Foo\\Bar', isBuiltin: false);
        $classNames = $type->getResolvableClassNames();
        self::assertCount(1, $classNames);
        self::assertSame('Foo\\Bar', $classNames[0]->fqn);
    }

    public function testNamedTypeWithPrimitiveFormatsCorrectly(): void
    {
        $type = new NamedType('string', isBuiltin: true);
        self::assertSame('string', $type->format());
    }

    public function testNamedTypeWithPrimitiveReturnsEmptyClassList(): void
    {
        $type = new NamedType('string', isBuiltin: true);
        self::assertSame([], $type->getResolvableClassNames());
    }

    public function testNamedTypeIsNotNullable(): void
    {
        $type = new NamedType('Foo', isBuiltin: false);
        self::assertFalse($type->isNullable());
    }

    public function testUnionTypeFormatsWithPipeSeparator(): void
    {
        $type = new UnionType([
            new NamedType('Foo', isBuiltin: false),
            new NamedType('Bar', isBuiltin: false),
        ]);
        self::assertSame('Foo|Bar', $type->format());
    }

    public function testUnionTypeReturnsAllClassNamesFromMembers(): void
    {
        $type = new UnionType([
            new NamedType('Foo', isBuiltin: false),
            new NamedType('string', isBuiltin: true),
            new NamedType('Bar', isBuiltin: false),
        ]);
        $classNames = $type->getResolvableClassNames();
        self::assertCount(2, $classNames);
        self::assertSame('Foo', $classNames[0]->fqn);
        self::assertSame('Bar', $classNames[1]->fqn);
    }

    public function testUnionTypeWithNullMemberReportsNullable(): void
    {
        $type = new UnionType([
            new NamedType('Foo', isBuiltin: false),
            new NamedType('null', isBuiltin: true),
        ]);
        self::assertTrue($type->isNullable());
    }

    public function testUnionTypeWithoutNullIsNotNullable(): void
    {
        $type = new UnionType([
            new NamedType('Foo', isBuiltin: false),
            new NamedType('Bar', isBuiltin: false),
        ]);
        self::assertFalse($type->isNullable());
    }

    public function testIntersectionTypeFormatsWithAmpersandSeparator(): void
    {
        $type = new IntersectionType([
            new NamedType('Foo', isBuiltin: false),
            new NamedType('Bar', isBuiltin: false),
        ]);
        self::assertSame('Foo&Bar', $type->format());
    }

    public function testIntersectionTypeReturnsAllClassNames(): void
    {
        $type = new IntersectionType([
            new NamedType('Foo', isBuiltin: false),
            new NamedType('Bar', isBuiltin: false),
        ]);
        $classNames = $type->getResolvableClassNames();
        self::assertCount(2, $classNames);
        self::assertSame('Foo', $classNames[0]->fqn);
        self::assertSame('Bar', $classNames[1]->fqn);
    }

    public function testIntersectionTypeIsNotNullable(): void
    {
        $type = new IntersectionType([
            new NamedType('Foo', isBuiltin: false),
            new NamedType('Bar', isBuiltin: false),
        ]);
        self::assertFalse($type->isNullable());
    }

    public function testNestedDnfTypeFormatsCorrectly(): void
    {
        $type = new UnionType([
            new IntersectionType([
                new NamedType('Foo', isBuiltin: false),
                new NamedType('Bar', isBuiltin: false),
            ]),
            new NamedType('null', isBuiltin: true),
        ]);
        self::assertSame('(Foo&Bar)|null', $type->format());
    }

    public function testNestedDnfTypeReturnsAllClassNames(): void
    {
        $type = new UnionType([
            new IntersectionType([
                new NamedType('Foo', isBuiltin: false),
                new NamedType('Bar', isBuiltin: false),
            ]),
            new NamedType('Baz', isBuiltin: false),
        ]);
        $classNames = $type->getResolvableClassNames();
        self::assertCount(3, $classNames);
        self::assertSame('Foo', $classNames[0]->fqn);
        self::assertSame('Bar', $classNames[1]->fqn);
        self::assertSame('Baz', $classNames[2]->fqn);
    }
}
