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
}
