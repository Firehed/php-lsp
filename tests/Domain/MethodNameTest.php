<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MethodName::class)]
class MethodNameTest extends TestCase
{
    public function testConstruction(): void
    {
        $owner = ClasslikeName::fromFullyQualified('Some\\Cls');
        $name = new MethodName($owner, 'doSomething');
        self::assertSame('doSomething', $name->name);
        self::assertSame($owner, $name->owner);
    }

    public function testEqualsTrue(): void
    {
        $owner = ClasslikeName::fromFullyQualified('Some\\Cls');
        $a = new MethodName($owner, 'doSomething');
        $b = new MethodName($owner, 'doSomething');
        self::assertTrue($a->equals($b));
    }

    public function testEqualsFalseForDifferentName(): void
    {
        $owner = ClasslikeName::fromFullyQualified('Some\\Cls');
        $a = new MethodName($owner, 'doSomething');
        $b = new MethodName($owner, 'doOther');
        self::assertFalse($a->equals($b));
    }

    public function testEqualsFalseForDifferentOwner(): void
    {
        $a = new MethodName(ClasslikeName::fromFullyQualified('Some\\ClsA'), 'doSomething');
        $b = new MethodName(ClasslikeName::fromFullyQualified('Some\\ClsB'), 'doSomething');
        self::assertFalse($a->equals($b), 'owner participates in method identity');
    }

    public function testEqualsCaseInsensitiveOnName(): void
    {
        $owner = ClasslikeName::fromFullyQualified('Some\\Cls');
        $a = new MethodName($owner, 'doSomething');
        $b = new MethodName($owner, 'DOSOMETHING');
        self::assertTrue($a->equals($b));
    }
}
