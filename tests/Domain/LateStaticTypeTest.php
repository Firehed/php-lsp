<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Domain;

use ArrayIterator;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\LateBindingKeyword;
use Firehed\PhpLsp\Domain\LateStaticType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Traversable;

#[CoversClass(LateStaticType::class)]
class LateStaticTypeTest extends TestCase
{
    public function testFormatReturnsKeyword(): void
    {
        $type = new LateStaticType(LateBindingKeyword::Static, new ClassName(Traversable::class));

        self::assertSame('static', $type->format());
    }

    public function testGetResolvableClassNamesReturnsDeclaringClass(): void
    {
        $type = new LateStaticType(LateBindingKeyword::Static, new ClassName(Traversable::class));

        $classNames = $type->getResolvableClassNames();

        self::assertCount(1, $classNames);
        self::assertSame(Traversable::class, $classNames[0]->fqn);
    }

    public function testIsNullableReturnsFalse(): void
    {
        $type = new LateStaticType(LateBindingKeyword::Static, new ClassName(Traversable::class));

        self::assertFalse($type->isNullable());
    }

    public function testResolveLateBoundStaticReturnsCallingClass(): void
    {
        $type = new LateStaticType(LateBindingKeyword::Static, new ClassName(Traversable::class));

        $resolved = $type->resolveLateBound(ArrayIterator::class);

        self::assertInstanceOf(ClassName::class, $resolved);
        self::assertSame(ArrayIterator::class, $resolved->fqn);
    }

    public function testResolveLateBoundSelfReturnsCallingClass(): void
    {
        $type = new LateStaticType(LateBindingKeyword::Self, new ClassName(Traversable::class));

        $resolved = $type->resolveLateBound(ArrayIterator::class);

        self::assertInstanceOf(ClassName::class, $resolved);
        self::assertSame(ArrayIterator::class, $resolved->fqn);
    }

    public function testResolveLateBoundParentReturnsDeclaringClass(): void
    {
        $type = new LateStaticType(LateBindingKeyword::Parent, new ClassName(Traversable::class));

        $resolved = $type->resolveLateBound(ArrayIterator::class);

        self::assertInstanceOf(ClassName::class, $resolved);
        self::assertSame(Traversable::class, $resolved->fqn);
    }
}
