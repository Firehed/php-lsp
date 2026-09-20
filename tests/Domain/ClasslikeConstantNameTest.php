<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClasslikeConstantName::class)]
class ClasslikeConstantNameTest extends TestCase
{
    public function testConstruction(): void
    {
        $owner = ClasslikeName::fromFullyQualified('Some\\Cls');
        $name = new ClasslikeConstantName($owner, 'MY_CONST');
        self::assertSame('MY_CONST', $name->name);
        self::assertSame($owner, $name->owner);
    }
}
