<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParameterInfo::class)]
class ParameterInfoTest extends TestCase
{
    public function testConstruction(): void
    {
        $param = new ParameterInfo(
            name: 'value',
            type: 'string',
            hasDefault: true,
            isVariadic: false,
            isPassedByReference: false,
        );

        self::assertSame('value', $param->name);
        self::assertSame('string', $param->type);
        self::assertTrue($param->hasDefault);
        self::assertFalse($param->isVariadic);
        self::assertFalse($param->isPassedByReference);
    }

    public function testConstructionWithNullType(): void
    {
        $param = new ParameterInfo(
            name: 'args',
            type: null,
            hasDefault: false,
            isVariadic: true,
            isPassedByReference: false,
        );

        self::assertNull($param->type);
        self::assertTrue($param->isVariadic);
    }
}
