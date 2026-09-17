<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Domain;

use ArrayIterator;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\LateBindingKeyword;
use Firehed\PhpLsp\Domain\LateStaticType;
use Firehed\PhpLsp\Domain\PrimitiveType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Traversable;

#[CoversClass(LateStaticType::class)]
class LateStaticTypeTest extends TestCase
{
    public function testFormatReturnsKeyword(): void
    {
        $type = new LateStaticType(LateBindingKeyword::Static, new ClasslikeName(Traversable::class));

        self::assertSame('static', $type->format());
    }

    public function testGetResolvableClasslikeNamesReturnsDeclaringClass(): void
    {
        $type = new LateStaticType(LateBindingKeyword::Static, new ClasslikeName(Traversable::class));

        $classNames = $type->getResolvableClasslikeNames();

        self::assertCount(1, $classNames);
        self::assertSame(Traversable::class, $classNames[0]->fqn);
    }

    public function testIsNullableReturnsFalse(): void
    {
        $type = new LateStaticType(LateBindingKeyword::Static, new ClasslikeName(Traversable::class));

        self::assertFalse($type->isNullable());
    }

    public function testResolveLateBoundStaticReturnsCallingClass(): void
    {
        $type = new LateStaticType(LateBindingKeyword::Static, new ClasslikeName(Traversable::class));

        $resolved = $type->resolveLateBound(ArrayIterator::class);

        self::assertInstanceOf(ClasslikeType::class, $resolved, 'static resolves to a concrete class-like type');
        self::assertSame(ArrayIterator::class, $resolved->name->fqn);
    }

    public function testResolveLateBoundSelfReturnsDeclaringClassForRegularClass(): void
    {
        $type = new LateStaticType(LateBindingKeyword::Self, new ClasslikeName(Traversable::class));

        $resolved = $type->resolveLateBound(ArrayIterator::class, declaringClassIsTrait: false);

        self::assertInstanceOf(ClasslikeType::class, $resolved, 'self resolves to the declaring class as a type');
        self::assertSame($type->declaringClass, $resolved->name);
    }

    public function testResolveLateBoundSelfReturnsCallingClassForTrait(): void
    {
        $type = new LateStaticType(LateBindingKeyword::Self, new ClasslikeName(Traversable::class));

        $resolved = $type->resolveLateBound(ArrayIterator::class, declaringClassIsTrait: true);

        self::assertInstanceOf(ClasslikeType::class, $resolved, 'self in a trait resolves to the using class');
        self::assertSame(ArrayIterator::class, $resolved->name->fqn);
    }

    public function testResolveLateBoundParentReturnsDeclaringClass(): void
    {
        $type = new LateStaticType(LateBindingKeyword::Parent, new ClasslikeName(Traversable::class));

        $resolved = $type->resolveLateBound(ArrayIterator::class);

        self::assertInstanceOf(ClasslikeType::class, $resolved, 'parent resolves to the recorded parent as a type');
        self::assertSame($type->declaringClass, $resolved->name);
    }

    public function testValueTypeIsNull(): void
    {
        $type = new LateStaticType(LateBindingKeyword::Static, new ClasslikeName(Traversable::class));

        self::assertNull($type->valueType());
    }

    public function testEqualsSameKeywordAndDeclaringClass(): void
    {
        $a = new LateStaticType(LateBindingKeyword::Static, new ClasslikeName(Traversable::class));
        $b = new LateStaticType(LateBindingKeyword::Static, new ClasslikeName(Traversable::class));
        self::assertTrue($a->equals($b));
    }

    public function testEqualsFalseWhenKeywordDiffers(): void
    {
        $a = new LateStaticType(LateBindingKeyword::Static, new ClasslikeName(Traversable::class));
        $b = new LateStaticType(LateBindingKeyword::Self, new ClasslikeName(Traversable::class));
        self::assertFalse($a->equals($b));
    }

    public function testEqualsFalseWhenDeclaringClassDiffers(): void
    {
        $a = new LateStaticType(LateBindingKeyword::Static, new ClasslikeName(Traversable::class));
        $b = new LateStaticType(LateBindingKeyword::Static, new ClasslikeName(ArrayIterator::class));
        self::assertFalse($a->equals($b));
    }

    public function testEqualsFalseAgainstDifferentTypeKind(): void
    {
        $a = new LateStaticType(LateBindingKeyword::Static, new ClasslikeName(Traversable::class));
        $b = new PrimitiveType('object');
        self::assertFalse($a->equals($b));
    }
}
