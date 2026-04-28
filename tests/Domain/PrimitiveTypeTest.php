<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PrimitiveType::class)]
class PrimitiveTypeTest extends TestCase
{
    public function testFormatReturnsName(): void
    {
        $type = new PrimitiveType('string');
        self::assertSame('string', $type->format());
    }

    public function testGetResolvableClassNamesReturnsEmptyList(): void
    {
        $type = new PrimitiveType('int');
        self::assertSame([], $type->getResolvableClassNames());
    }

    public function testNullIsNullable(): void
    {
        $type = new PrimitiveType('null');
        self::assertTrue($type->isNullable());
    }

    public function testNonNullIsNotNullable(): void
    {
        $type = new PrimitiveType('string');
        self::assertFalse($type->isNullable());
    }
}
