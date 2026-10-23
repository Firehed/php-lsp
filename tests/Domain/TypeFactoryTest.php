<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Domain;

use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\IntersectionType;
use Firehed\PhpLsp\Domain\LateBindingKeyword;
use Firehed\PhpLsp\Domain\LateStaticType;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\TypeFactory;
use Firehed\PhpLsp\Domain\UnionType;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType as AstIntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType as AstUnionType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TypeFactory::class)]
class TypeFactoryTest extends TestCase
{
    public function testFromNodeWithNullReturnsNull(): void
    {
        self::assertNull(TypeFactory::fromNode(null));
    }

    public function testFromNodeWithNameCreatesClasslikeType(): void
    {
        $node = new Name(\stdClass::class);
        $type = TypeFactory::fromNode($node);

        self::assertInstanceOf(ClasslikeType::class, $type);
        self::assertSame(\stdClass::class, $type->name->qualifiedName->fullyQualifiedName());
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

    /**
     * @return iterable<string, array{Identifier|Name, LateBindingKeyword}>
     */
    public static function lateBindingKeywords(): iterable
    {
        foreach (LateBindingKeyword::cases() as $keyword) {
            yield "{$keyword->value} as an identifier" => [new Identifier($keyword->value), $keyword];
            yield "{$keyword->value} as a name" => [new Name($keyword->value), $keyword];
        }
    }

    #[DataProvider('lateBindingKeywords')]
    public function testALateBindingKeywordIsKeptForWhereItIsUsedToBind(
        Identifier|Name $node,
        LateBindingKeyword $keyword,
    ): void {
        self::assertEquals(
            new LateStaticType($keyword),
            TypeFactory::fromNode($node),
            'the class the keyword names is bound where the type is used',
        );
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
}
