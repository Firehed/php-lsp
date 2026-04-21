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
}
