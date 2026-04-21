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
        $name = new MethodName('doSomething');
        self::assertSame('doSomething', $name->name);
    }

    public function testEqualsTrue(): void
    {
        $a = new MethodName('doSomething');
        $b = new MethodName('doSomething');
        self::assertTrue($a->equals($b));
    }

    public function testEqualsFalse(): void
    {
        $a = new MethodName('doSomething');
        $b = new MethodName('doOther');
        self::assertFalse($a->equals($b));
    }

    public function testEqualsCaseInsensitive(): void
    {
        $a = new MethodName('doSomething');
        $b = new MethodName('DOSOMETHING');
        self::assertTrue($a->equals($b));
    }
}
