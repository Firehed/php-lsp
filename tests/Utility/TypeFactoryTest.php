<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\IntersectionType;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\UnionType;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType as AstIntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType as AstUnionType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use ReflectionMethod;

#[CoversClass(TypeFactory::class)]
class TypeFactoryTest extends TestCase
{
    public function testFromNodeWithNullReturnsNull(): void
    {
        self::assertNull(TypeFactory::fromNode(null));
    }

    public function testFromNodeWithNameCreatesClassName(): void
    {
        $node = new Name(\stdClass::class);
        $type = TypeFactory::fromNode($node);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(\stdClass::class, $type->fqn);
    }

    public function testFromNodeWithNameUsesResolvedName(): void
    {
        $node = new Name('User');
        $node->setAttribute('resolvedName', new Name('App\\Models\\User'));
        $type = TypeFactory::fromNode($node);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame('App\\Models\\User', $type->fqn);
    }

    /**
     * @return iterable<string, array{string}>
     * @codeCoverageIgnore
     */
    public static function primitiveProvider(): iterable
    {
        yield 'string' => ['string'];
        yield 'int' => ['int'];
        yield 'float' => ['float'];
        yield 'bool' => ['bool'];
        yield 'array' => ['array'];
        yield 'object' => ['object'];
        yield 'callable' => ['callable'];
        yield 'iterable' => ['iterable'];
        yield 'void' => ['void'];
        yield 'never' => ['never'];
        yield 'mixed' => ['mixed'];
        yield 'null' => ['null'];
        yield 'true' => ['true'];
        yield 'false' => ['false'];
    }

    #[DataProvider('primitiveProvider')]
    public function testFromNodeWithPrimitiveIdentifierCreatesPrimitiveType(string $name): void
    {
        $node = new Identifier($name);
        $type = TypeFactory::fromNode($node);

        self::assertInstanceOf(PrimitiveType::class, $type);
        self::assertSame($name, $type->format());
    }

    public function testFromNodeWithSelfAndContextCreatesClassName(): void
    {
        $node = new Identifier('self');
        $type = TypeFactory::fromNode($node, selfContext: \stdClass::class);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(\stdClass::class, $type->fqn);
    }

    public function testFromNodeWithStaticAndContextCreatesClassName(): void
    {
        $node = new Identifier('static');
        $type = TypeFactory::fromNode($node, selfContext: \ArrayObject::class);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(\ArrayObject::class, $type->fqn);
    }

    public function testFromNodeWithParentAndContextCreatesClassName(): void
    {
        $node = new Identifier('parent');
        $type = TypeFactory::fromNode($node, parentContext: \Throwable::class);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(\Throwable::class, $type->fqn);
    }

    public function testFromNodeWithSelfWithoutContextCreatesPrimitiveType(): void
    {
        $node = new Identifier('self');
        $type = TypeFactory::fromNode($node);

        self::assertInstanceOf(PrimitiveType::class, $type);
        self::assertSame('self', $type->format());
    }

    public function testFromNodeWithParentWithoutContextCreatesPrimitiveType(): void
    {
        $node = new Identifier('parent');
        $type = TypeFactory::fromNode($node);

        self::assertInstanceOf(PrimitiveType::class, $type);
        self::assertSame('parent', $type->format());
    }

    public function testFromNodeWithNullableTypeCreatesUnionType(): void
    {
        $node = new NullableType(new Name(\stdClass::class));
        $type = TypeFactory::fromNode($node);

        self::assertInstanceOf(UnionType::class, $type);
        self::assertTrue($type->isNullable());
        self::assertSame('?stdClass', $type->format());
    }

    public function testFromNodeWithUnionTypeCreatesUnionType(): void
    {
        $node = new AstUnionType([
            new Name(\Iterator::class),
            new Name(\Countable::class),
        ]);
        $type = TypeFactory::fromNode($node);

        self::assertInstanceOf(UnionType::class, $type);
        self::assertSame('Iterator|Countable', $type->format());
    }

    public function testFromNodeWithIntersectionTypeCreatesIntersectionType(): void
    {
        $node = new AstIntersectionType([
            new Name(\Iterator::class),
            new Name(\Countable::class),
        ]);
        $type = TypeFactory::fromNode($node);

        self::assertInstanceOf(IntersectionType::class, $type);
        self::assertSame('Iterator&Countable', $type->format());
    }

    public function testFromNodeWithDnfType(): void
    {
        $node = new AstUnionType([
            new AstIntersectionType([
                new Name(\Iterator::class),
                new Name(\Countable::class),
            ]),
            new Identifier('null'),
        ]);
        $type = TypeFactory::fromNode($node);

        self::assertInstanceOf(UnionType::class, $type);
        self::assertSame('(Iterator&Countable)|null', $type->format());
        self::assertTrue($type->isNullable());
    }

    public function testFromReflectionWithNullReturnsNull(): void
    {
        self::assertNull(TypeFactory::fromReflection(null));
    }

    public function testFromReflectionWithBuiltinCreatesPrimitiveType(): void
    {
        $func = new ReflectionFunction(fn (): string => '');
        $type = TypeFactory::fromReflection($func->getReturnType());

        self::assertInstanceOf(PrimitiveType::class, $type);
        self::assertSame('string', $type->format());
    }

    public function testFromReflectionWithNullableBuiltinCreatesUnionType(): void
    {
        $func = new ReflectionFunction(fn (): ?string => null);
        $type = TypeFactory::fromReflection($func->getReturnType());

        self::assertInstanceOf(UnionType::class, $type);
        self::assertTrue($type->isNullable());
        self::assertSame('?string', $type->format());
    }

    public function testFromReflectionWithClassCreatesClassName(): void
    {
        $func = new ReflectionFunction(fn (): \stdClass => new \stdClass());
        $type = TypeFactory::fromReflection($func->getReturnType());

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(\stdClass::class, $type->fqn);
    }

    public function testFromReflectionWithNullableCreatesUnionType(): void
    {
        $func = new ReflectionFunction(fn (): ?\stdClass => null);
        $type = TypeFactory::fromReflection($func->getReturnType());

        self::assertInstanceOf(UnionType::class, $type);
        self::assertTrue($type->isNullable());
        self::assertSame('?stdClass', $type->format());
    }

    public function testFromReflectionWithUnionCreatesUnionType(): void
    {
        $func = new ReflectionFunction(fn (): \Iterator|\Countable => new \ArrayIterator());
        $type = TypeFactory::fromReflection($func->getReturnType());

        self::assertInstanceOf(UnionType::class, $type);
        self::assertSame('Iterator|Countable', $type->format());
    }

    public function testFromReflectionWithIntersectionCreatesIntersectionType(): void
    {
        $func = new ReflectionFunction(fn (): \Iterator&\Countable => new \ArrayIterator());
        $type = TypeFactory::fromReflection($func->getReturnType());

        self::assertInstanceOf(IntersectionType::class, $type);
        self::assertSame('Iterator&Countable', $type->format());
    }

    public function testFromReflectionWithMixedDoesNotWrapInUnion(): void
    {
        $func = new ReflectionFunction(fn (): mixed => null);
        $type = TypeFactory::fromReflection($func->getReturnType());

        self::assertInstanceOf(PrimitiveType::class, $type);
        self::assertSame('mixed', $type->format());
    }

    public function testFromReflectionWithNullDoesNotWrapInUnion(): void
    {
        $func = new ReflectionFunction(fn (): null => null);
        $type = TypeFactory::fromReflection($func->getReturnType());

        self::assertInstanceOf(PrimitiveType::class, $type);
        self::assertSame('null', $type->format());
    }
}
