<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return iterable<string, array{string, ?PrimitiveType}>
     */
    public static function names(): iterable
    {
        yield 'lowercase' => ['int', PrimitiveType::Int];
        yield 'uppercase' => ['INT', PrimitiveType::Int];
        yield 'mixed case' => ['Iterable', PrimitiveType::Iterable];
        yield 'a late-binding keyword' => ['self', null];
        yield 'a class name' => ['Integer', null];
    }

    #[DataProvider('names')]
    public function testTryFromName(string $name, ?PrimitiveType $expected): void
    {
        self::assertSame($expected, PrimitiveType::tryFromName($name), 'PHP matches type names case-insensitively');
    }

    /**
     * @return iterable<string, array{PrimitiveType, TypeHintContext, bool}>
     */
    public static function positions(): iterable
    {
        foreach ([PrimitiveType::Void, PrimitiveType::Never] as $type) {
            yield "{$type->value} as a ReturnType type" => [$type, TypeHintContext::ReturnType, true];
            yield "{$type->value} as a Parameter type" => [$type, TypeHintContext::Parameter, false];
            yield "{$type->value} as a Property type" => [$type, TypeHintContext::Property, false];
        }
        yield 'callable as a ReturnType type' => [PrimitiveType::Callable, TypeHintContext::ReturnType, true];
        yield 'callable as a Parameter type' => [PrimitiveType::Callable, TypeHintContext::Parameter, true];
        yield 'callable as a Property type' => [PrimitiveType::Callable, TypeHintContext::Property, false];
        foreach (TypeHintContext::cases() as $context) {
            yield "int as a {$context->name} type" => [PrimitiveType::Int, $context, true];
        }
    }

    #[DataProvider('positions')]
    public function testValidIn(PrimitiveType $type, TypeHintContext $context, bool $expected): void
    {
        self::assertSame(
            $expected,
            $type->validIn($context),
            'void and never are return types only, and a property cannot be callable',
        );
    }
}
