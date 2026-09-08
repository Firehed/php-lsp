<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Domain;

use Firehed\PhpLsp\Domain\ClassName;
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
use ReflectionFunction;

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

    public function testFromNodeWithIdentifierSelfAndPreserveLateBindingCreatesLateStaticType(): void
    {
        $node = new Identifier('self');
        $type = TypeFactory::fromNode($node, selfContext: \stdClass::class, preserveLateBinding: true);

        self::assertInstanceOf(LateStaticType::class, $type);
        self::assertSame(LateBindingKeyword::Self, $type->keyword);
        self::assertSame(\stdClass::class, $type->declaringClass->fqn);
    }

    public function testFromNodeWithIdentifierStaticAndPreserveLateBindingCreatesLateStaticType(): void
    {
        $node = new Identifier('static');
        $type = TypeFactory::fromNode($node, selfContext: \ArrayObject::class, preserveLateBinding: true);

        self::assertInstanceOf(LateStaticType::class, $type);
        self::assertSame(LateBindingKeyword::Static, $type->keyword);
        self::assertSame(\ArrayObject::class, $type->declaringClass->fqn);
    }

    public function testFromNodeWithIdentifierParentAndPreserveLateBindingCreatesLateStaticType(): void
    {
        $node = new Identifier('parent');
        $type = TypeFactory::fromNode($node, parentContext: \Throwable::class, preserveLateBinding: true);

        self::assertInstanceOf(LateStaticType::class, $type);
        self::assertSame(LateBindingKeyword::Parent, $type->keyword);
        self::assertSame(\Throwable::class, $type->declaringClass->fqn);
    }

    public function testFromNodeWithParentWithoutContextCreatesPrimitiveType(): void
    {
        $node = new Identifier('parent');
        $type = TypeFactory::fromNode($node);

        self::assertInstanceOf(PrimitiveType::class, $type);
        self::assertSame('parent', $type->format());
    }

    public function testFromNodeWithNameSelfAndContextCreatesClassName(): void
    {
        $node = new Name('self');
        $type = TypeFactory::fromNode($node, selfContext: \stdClass::class);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(\stdClass::class, $type->fqn);
    }

    public function testFromNodeWithNameStaticAndContextCreatesClassName(): void
    {
        $node = new Name('static');
        $type = TypeFactory::fromNode($node, selfContext: \ArrayObject::class);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(\ArrayObject::class, $type->fqn);
    }

    public function testFromNodeWithNameParentAndContextCreatesClassName(): void
    {
        $node = new Name('parent');
        $type = TypeFactory::fromNode($node, parentContext: \Throwable::class);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(\Throwable::class, $type->fqn);
    }

    public function testFromNodeWithNameSelfWithoutContextCreatesPrimitiveType(): void
    {
        $node = new Name('self');
        $type = TypeFactory::fromNode($node);

        self::assertInstanceOf(PrimitiveType::class, $type);
        self::assertSame('self', $type->format());
    }

    public function testFromNodeWithNameSelfAndPreserveLateBindingCreatesLateStaticType(): void
    {
        $node = new Name('self');
        $type = TypeFactory::fromNode($node, selfContext: \stdClass::class, preserveLateBinding: true);

        self::assertInstanceOf(LateStaticType::class, $type);
        self::assertSame(LateBindingKeyword::Self, $type->keyword);
        self::assertSame(\stdClass::class, $type->declaringClass->fqn);
    }

    public function testFromNodeWithNameStaticAndPreserveLateBindingCreatesLateStaticType(): void
    {
        $node = new Name('static');
        $type = TypeFactory::fromNode($node, selfContext: \ArrayObject::class, preserveLateBinding: true);

        self::assertInstanceOf(LateStaticType::class, $type);
        self::assertSame(LateBindingKeyword::Static, $type->keyword);
        self::assertSame(\ArrayObject::class, $type->declaringClass->fqn);
    }

    public function testFromNodeWithNameParentAndPreserveLateBindingCreatesLateStaticType(): void
    {
        $node = new Name('parent');
        $type = TypeFactory::fromNode($node, parentContext: \Throwable::class, preserveLateBinding: true);

        self::assertInstanceOf(LateStaticType::class, $type);
        self::assertSame(LateBindingKeyword::Parent, $type->keyword);
        self::assertSame(\Throwable::class, $type->declaringClass->fqn);
    }

    public function testFromNodeWithNameParentWithoutContextCreatesPrimitiveType(): void
    {
        $node = new Name('parent');
        $type = TypeFactory::fromNode($node);

        self::assertInstanceOf(PrimitiveType::class, $type);
        self::assertSame('parent', $type->format());
    }

    public function testFromNodeWithNameStaticWithoutContextCreatesPrimitiveType(): void
    {
        $node = new Name('static');
        $type = TypeFactory::fromNode($node);

        self::assertInstanceOf(PrimitiveType::class, $type);
        self::assertSame('static', $type->format());
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

    public function testClassNameCreatesClassNameFromFqn(): void
    {
        $type = TypeFactory::className(\stdClass::class);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(\stdClass::class, $type->fqn);
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

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function docblockTypeProvider(): iterable
    {
        yield 'plain class' => ['App\\Models\\User', 'App\\Models\\User', null];
        yield 'primitive' => ['string', 'string', null];
        yield 'null' => ['null', 'null', null];
        yield 'nullable class' => ['?App\\Models\\User', '?App\\Models\\User', null];
        yield 'nullable primitive' => ['?int', '?int', null];
        yield 'union' => ['App\\A|App\\B', 'App\\A|App\\B', null];
        yield 'union with null' => ['App\\A|null', '?App\\A', null];
        yield 'array short' => ['App\\User[]', 'array', 'App\\User'];
        yield 'nullable array short' => ['?App\\User[]', '?array', null];
        yield 'array generic' => ['array<App\\User>', 'array', 'App\\User'];
        yield 'array key-value' => ['array<int, App\\User>', 'array', 'App\\User'];
        yield 'list generic' => ['list<App\\User>', 'array', 'App\\User'];
        yield 'iterable generic' => ['iterable<App\\User>', 'iterable', 'App\\User'];
        yield 'iterable key-value' => ['iterable<string, App\\User>', 'iterable', 'App\\User'];
        yield 'class with type arg' => ['App\\Collection<App\\User>', 'App\\Collection', 'App\\User'];
    }

    #[DataProvider('docblockTypeProvider')]
    public function testFromDocblockType(string $input, string $format, ?string $valueTypeFormat): void
    {
        $type = TypeFactory::fromDocblockType($input);
        self::assertNotNull($type, 'the input is representable');
        self::assertSame($format, $type->format(), 'outer format matches');
        $valueType = $type->valueType();
        if ($valueTypeFormat === null) {
            self::assertNull($valueType, 'no value type expected');
        } else {
            self::assertNotNull($valueType, 'value type populated');
            self::assertSame($valueTypeFormat, $valueType->format(), 'value type matches');
        }
    }

    public function testFromDocblockTypeReturnsNullOnEmpty(): void
    {
        self::assertNull(TypeFactory::fromDocblockType(''), 'empty input has no type');
    }

    public function testMergeReturnsNullWhenBothNull(): void
    {
        self::assertNull(TypeFactory::merge(null, null), 'nothing to merge');
    }

    public function testMergeReturnsDocblockWhenNativeNull(): void
    {
        $docblock = TypeFactory::className(\stdClass::class);
        self::assertSame($docblock, TypeFactory::merge(null, $docblock), 'docblock stands in for missing native');
    }

    public function testMergeReturnsNativeWhenDocblockNull(): void
    {
        $native = TypeFactory::className(\stdClass::class);
        self::assertSame($native, TypeFactory::merge($native, null), 'native alone survives');
    }

    public function testMergeNativeArrayGainsDocblockValueType(): void
    {
        $native = TypeFactory::primitive('array');
        $docblock = TypeFactory::fromDocblockType('list<App\\Models\\User>');
        $merged = TypeFactory::merge($native, $docblock);
        self::assertNotNull($merged);
        self::assertSame('array', $merged->format(), 'array container preserved');
        self::assertSame('App\\Models\\User', $merged->valueType()?->format(), 'value type filled from docblock');
    }

    public function testMergeNativeIterableGainsDocblockValueType(): void
    {
        $native = TypeFactory::primitive('iterable');
        $docblock = TypeFactory::fromDocblockType('iterable<App\\Models\\User>');
        $merged = TypeFactory::merge($native, $docblock);
        self::assertNotNull($merged);
        self::assertSame('iterable', $merged->format());
        self::assertSame('App\\Models\\User', $merged->valueType()?->format(), 'value type filled from docblock');
    }

    public function testMergeNativeClassGainsDocblockValueType(): void
    {
        $native = TypeFactory::className('App\\Collection');
        $docblock = TypeFactory::fromDocblockType('App\\Collection<App\\Models\\User>');
        $merged = TypeFactory::merge($native, $docblock);
        self::assertNotNull($merged);
        self::assertSame('App\\Collection', $merged->format());
        self::assertSame('App\\Models\\User', $merged->valueType()?->format(), 'generic argument carried over');
    }

    public function testMergeNativeWinsOnConflict(): void
    {
        $native = TypeFactory::primitive('int');
        $docblock = TypeFactory::className(\stdClass::class);
        self::assertSame($native, TypeFactory::merge($native, $docblock), 'native beats an unrelated docblock');
    }

    public function testMergeNativeWithExistingValueTypeIsUnchanged(): void
    {
        $native = TypeFactory::fromDocblockType('array<App\\A>');
        $docblock = TypeFactory::fromDocblockType('array<App\\B>');
        $merged = TypeFactory::merge($native, $docblock);
        self::assertSame($native, $merged, 'native already has typeArguments; docblock does not override');
    }

    public function testUnionCollapsesToSingleMember(): void
    {
        $type = TypeFactory::className(\DateTime::class);
        self::assertSame($type, TypeFactory::union([$type]));
    }

    public function testUnionOfSeveralMembersBuildsUnionType(): void
    {
        $a = TypeFactory::className(\DateTime::class);
        $b = TypeFactory::className(\DateTimeImmutable::class);
        $type = TypeFactory::union([$a, $b]);
        self::assertInstanceOf(UnionType::class, $type);
        self::assertSame('DateTime|DateTimeImmutable', $type->format());
    }
}
