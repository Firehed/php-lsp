<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConstantName::class)]
class ConstantNameTest extends TestCase
{
    public function testConstruction(): void
    {
        $name = new ConstantName('MY_CONST');
        self::assertSame('MY_CONST', $name->name);
    }
}
