<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Utility;

use Firehed\PhpLsp\Utility\TypeFormatter;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use ReflectionMethod;

#[CoversClass(TypeFormatter::class)]
class TypeFormatterTest extends TestCase
{
    public function testFormatNodeWithNull(): void
    {
        self::assertNull(TypeFormatter::formatNode(null));
    }

    public function testFormatNodeWithName(): void
    {
        $node = new Name('SomeClass');
        self::assertSame('SomeClass', TypeFormatter::formatNode($node));
    }

    public function testFormatNodeWithFullyQualifiedName(): void
    {
        $node = new Name\FullyQualified('Some\\Namespace\\SomeClass');
        self::assertSame('Some\\Namespace\\SomeClass', TypeFormatter::formatNode($node));
    }

    public function testFormatNodeWithIdentifier(): void
    {
        $node = new Identifier('string');
        self::assertSame('string', TypeFormatter::formatNode($node));
    }

    public function testFormatNodeWithNullableType(): void
    {
        $node = new Node\NullableType(new Identifier('string'));
        self::assertSame('?string', TypeFormatter::formatNode($node));
    }

    public function testFormatNodeWithUnionType(): void
    {
        $node = new Node\UnionType([
            new Identifier('string'),
            new Identifier('int'),
        ]);
        self::assertSame('string|int', TypeFormatter::formatNode($node));
    }

    public function testFormatNodeWithIntersectionType(): void
    {
        $node = new Node\IntersectionType([
            new Name('Countable'),
            new Name('Iterator'),
        ]);
        self::assertSame('Countable&Iterator', TypeFormatter::formatNode($node));
    }

    public function testFormatNodeWithUnionTypeIncludingNull(): void
    {
        $node = new Node\UnionType([
            new Identifier('string'),
            new Identifier('int'),
            new Identifier('null'),
        ]);
        self::assertSame('string|int|null', TypeFormatter::formatNode($node));
    }

    public function testFormatReflectionWithNamedType(): void
    {
        $fn = new ReflectionFunction(fn(): string => 'test');
        $type = $fn->getReturnType();
        assert($type !== null);
        self::assertSame('string', TypeFormatter::formatReflection($type));
    }

    public function testFormatReflectionWithNullableType(): void
    {
        $fn = new ReflectionFunction(fn(): ?string => null);
        $type = $fn->getReturnType();
        assert($type !== null);
        self::assertSame('?string', TypeFormatter::formatReflection($type));
    }

    public function testFormatReflectionWithUnionType(): void
    {
        $fn = new ReflectionFunction(fn(bool $b): string|int => $b ? 'test' : 1);
        $type = $fn->getReturnType();
        assert($type !== null);
        self::assertSame('string|int', TypeFormatter::formatReflection($type));
    }

    public function testFormatReflectionWithMixedDoesNotAddNullable(): void
    {
        $fn = new ReflectionFunction(fn(): mixed => null);
        $type = $fn->getReturnType();
        assert($type !== null);
        self::assertSame('mixed', TypeFormatter::formatReflection($type));
    }

    public function testFormatReflectionWithNullDoesNotAddNullable(): void
    {
        $fn = new ReflectionFunction(fn(): null => null);
        $type = $fn->getReturnType();
        assert($type !== null);
        self::assertSame('null', TypeFormatter::formatReflection($type));
    }

    public function testFormatReflectionWithIntersectionType(): void
    {
        $obj = new class {
            /** @return \Countable&\Traversable<mixed, mixed> */
            public function test(): \Countable&\Traversable
            {
                return new \ArrayObject();
            }
        };
        $method = new ReflectionMethod($obj, 'test');
        $type = $method->getReturnType();
        assert($type !== null);
        self::assertSame('Countable&Traversable', TypeFormatter::formatReflection($type));
    }
}
