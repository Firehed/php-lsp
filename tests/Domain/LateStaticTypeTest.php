<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Domain;

use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\LateBinding;
use Firehed\PhpLsp\Domain\LateBindingKeyword;
use Firehed\PhpLsp\Domain\LateStaticType;
use Firehed\PhpLsp\Domain\PrimitiveType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LateStaticType::class)]
class LateStaticTypeTest extends TestCase
{
    public function testFormatReturnsKeyword(): void
    {
        self::assertSame('static', (new LateStaticType(LateBindingKeyword::Static))->format());
    }

    public function testAnUnboundKeywordNamesNoClass(): void
    {
        self::assertSame([], (new LateStaticType(LateBindingKeyword::Static))->getResolvableClasslikeNames());
    }

    public function testIsNullableReturnsFalse(): void
    {
        self::assertFalse((new LateStaticType(LateBindingKeyword::Static))->isNullable());
    }

    #[DataProvider('keywords')]
    public function testResolvesToTheClassTheBindingNames(LateBindingKeyword $keyword, string $expected): void
    {
        $resolved = (new LateStaticType($keyword))->resolveLateBound(self::binding());

        self::assertInstanceOf(ClasslikeType::class, $resolved, "{$keyword->value} resolves to a class-like type");
        self::assertSame($expected, $resolved->name->qualifiedName->fullyQualifiedName());
    }

    /**
     * @return iterable<string, array{LateBindingKeyword, string}>
     */
    public static function keywords(): iterable
    {
        yield 'static' => [LateBindingKeyword::Static, 'App\\Called'];
        yield 'self' => [LateBindingKeyword::Self, 'App\\Declaring'];
        yield 'parent' => [LateBindingKeyword::Parent, 'App\\Base'];
    }

    public function testParentStaysUnboundWithoutAParent(): void
    {
        $type = new LateStaticType(LateBindingKeyword::Parent);
        $name = ClasslikeName::fromFullyQualified('App\\Root');

        self::assertSame($type, $type->resolveLateBound(new LateBinding($name, $name, null)), 'nothing to bind to');
    }

    public function testEqualsSameKeyword(): void
    {
        self::assertTrue(
            (new LateStaticType(LateBindingKeyword::Static))->equals(new LateStaticType(LateBindingKeyword::Static)),
        );
    }

    public function testEqualsFalseWhenKeywordDiffers(): void
    {
        self::assertFalse(
            (new LateStaticType(LateBindingKeyword::Static))->equals(new LateStaticType(LateBindingKeyword::Self)),
        );
    }

    public function testEqualsFalseAgainstDifferentTypeKind(): void
    {
        self::assertFalse((new LateStaticType(LateBindingKeyword::Static))->equals(new PrimitiveType('object')));
    }

    private static function binding(): LateBinding
    {
        return new LateBinding(
            ClasslikeName::fromFullyQualified('App\\Called'),
            ClasslikeName::fromFullyQualified('App\\Declaring'),
            ClasslikeName::fromFullyQualified('App\\Base'),
        );
    }
}
