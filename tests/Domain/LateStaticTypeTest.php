<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Domain;

use ArrayIterator;
use Countable;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\LateStaticType;
use IteratorAggregate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Traversable;

#[CoversClass(LateStaticType::class)]
class LateStaticTypeTest extends TestCase
{
    public function testFormatReturnsKeyword(): void
    {
        $type = new LateStaticType('static', new ClassName(Traversable::class));

        self::assertSame('static', $type->format());
    }

    public function testGetResolvableClassNamesReturnsDeclaringClass(): void
    {
        $type = new LateStaticType('static', new ClassName(Traversable::class));

        $classNames = $type->getResolvableClassNames();

        self::assertCount(1, $classNames);
        self::assertSame(Traversable::class, $classNames[0]->fqn);
    }

    public function testIsNullableReturnsFalse(): void
    {
        $type = new LateStaticType('static', new ClassName(Traversable::class));

        self::assertFalse($type->isNullable());
    }

    public function testResolveStaticReturnsCallingClass(): void
    {
        $type = new LateStaticType('static', new ClassName(Traversable::class));

        $resolved = $type->resolve(ArrayIterator::class);

        self::assertSame(ArrayIterator::class, $resolved->fqn);
    }

    public function testResolveSelfReturnsCallingClass(): void
    {
        $type = new LateStaticType('self', new ClassName(Traversable::class));

        $resolved = $type->resolve(ArrayIterator::class);

        self::assertSame(ArrayIterator::class, $resolved->fqn);
    }

    public function testResolveParentReturnsCallingParent(): void
    {
        $type = new LateStaticType('parent', new ClassName(Traversable::class));

        $resolved = $type->resolve(ArrayIterator::class, IteratorAggregate::class);

        self::assertSame(IteratorAggregate::class, $resolved->fqn);
    }

    public function testResolveParentFallsBackToDeclaringClass(): void
    {
        $type = new LateStaticType('parent', new ClassName(Traversable::class));

        $resolved = $type->resolve(ArrayIterator::class, null);

        self::assertSame(Traversable::class, $resolved->fqn);
    }
}
