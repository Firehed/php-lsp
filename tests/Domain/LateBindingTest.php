<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Domain;

use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\LateBinding;
use Firehed\PhpLsp\Domain\LateBindingKeyword;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LateBinding::class)]
final class LateBindingTest extends TestCase
{
    public function testEachKeywordNamesItsClass(): void
    {
        $static = ClasslikeName::fromFullyQualified('App\\Called');
        $self = ClasslikeName::fromFullyQualified('App\\Declaring');
        $parent = ClasslikeName::fromFullyQualified('App\\Base');
        $binding = new LateBinding($static, $self, $parent);

        self::assertSame($static, $binding->classFor(LateBindingKeyword::Static), 'static is the class called on');
        self::assertSame($self, $binding->classFor(LateBindingKeyword::Self), 'self is the declaring class');
        self::assertSame($parent, $binding->classFor(LateBindingKeyword::Parent), 'parent is its parent');
    }

    public function testParentIsNullForAClassWithoutOne(): void
    {
        $name = ClasslikeName::fromFullyQualified('App\\Root');

        self::assertNull((new LateBinding($name, $name, null))->classFor(LateBindingKeyword::Parent));
    }
}
