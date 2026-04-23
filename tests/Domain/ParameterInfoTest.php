<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

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

    public function testFormatSimple(): void
    {
        $param = new ParameterInfo(
            name: 'value',
            type: 'string',
            hasDefault: false,
            isVariadic: false,
            isPassedByReference: false,
        );

        self::assertSame('string $value', $param->format());
    }

    public function testFormatNoType(): void
    {
        $param = new ParameterInfo(
            name: 'value',
            type: null,
            hasDefault: false,
            isVariadic: false,
            isPassedByReference: false,
        );

        self::assertSame('$value', $param->format());
    }

    public function testFormatVariadic(): void
    {
        $param = new ParameterInfo(
            name: 'args',
            type: 'string',
            hasDefault: false,
            isVariadic: true,
            isPassedByReference: false,
        );

        self::assertSame('string ...$args', $param->format());
    }

    public function testFormatWithDefaultHidden(): void
    {
        $param = new ParameterInfo(
            name: 'value',
            type: 'int',
            hasDefault: true,
            isVariadic: false,
            isPassedByReference: false,
        );

        self::assertSame('int $value', $param->format());
    }

    public function testFormatWithDefaultShown(): void
    {
        $param = new ParameterInfo(
            name: 'value',
            type: 'int',
            hasDefault: true,
            isVariadic: false,
            isPassedByReference: false,
        );

        self::assertSame('int $value = ...', $param->format(showDefault: true));
    }

    public function testFormatVariadicIgnoresDefault(): void
    {
        $param = new ParameterInfo(
            name: 'args',
            type: 'string',
            hasDefault: true,
            isVariadic: true,
            isPassedByReference: false,
        );

        self::assertSame('string ...$args', $param->format(showDefault: true));
    }

    public function testFormatPassedByReference(): void
    {
        $param = new ParameterInfo(
            name: 'value',
            type: 'string',
            hasDefault: false,
            isVariadic: false,
            isPassedByReference: true,
        );

        self::assertSame('string &$value', $param->format());
    }

    public function testFormatVariadicByReference(): void
    {
        $param = new ParameterInfo(
            name: 'args',
            type: 'array',
            hasDefault: false,
            isVariadic: true,
            isPassedByReference: true,
        );

        self::assertSame('array &...$args', $param->format());
    }

    public function testFromReflectionSimple(): void
    {
        $fn = function (string $value) {};
        $reflectionParam = (new ReflectionFunction($fn))->getParameters()[0];

        $param = ParameterInfo::fromReflection($reflectionParam);

        self::assertSame('value', $param->name);
        self::assertSame('string', $param->type);
        self::assertFalse($param->hasDefault);
        self::assertFalse($param->isVariadic);
        self::assertFalse($param->isPassedByReference);
    }

    public function testFromReflectionWithDefault(): void
    {
        $fn = function (int $count = 10) {};
        $reflectionParam = (new ReflectionFunction($fn))->getParameters()[0];

        $param = ParameterInfo::fromReflection($reflectionParam);

        self::assertSame('count', $param->name);
        self::assertSame('int', $param->type);
        self::assertTrue($param->hasDefault);
        self::assertFalse($param->isVariadic);
        self::assertFalse($param->isPassedByReference);
    }

    public function testFromReflectionVariadic(): void
    {
        $fn = function (string ...$args) {};
        $reflectionParam = (new ReflectionFunction($fn))->getParameters()[0];

        $param = ParameterInfo::fromReflection($reflectionParam);

        self::assertSame('args', $param->name);
        self::assertSame('string', $param->type);
        self::assertFalse($param->hasDefault);
        self::assertTrue($param->isVariadic);
        self::assertFalse($param->isPassedByReference);
    }

    public function testFromReflectionByReference(): void
    {
        $fn = function (array &$data) {};
        $reflectionParam = (new ReflectionFunction($fn))->getParameters()[0];

        $param = ParameterInfo::fromReflection($reflectionParam);

        self::assertSame('data', $param->name);
        self::assertSame('array', $param->type);
        self::assertFalse($param->hasDefault);
        self::assertFalse($param->isVariadic);
        self::assertTrue($param->isPassedByReference);
    }

    public function testFromReflectionNoType(): void
    {
        $fn = function ($untyped) {};
        $reflectionParam = (new ReflectionFunction($fn))->getParameters()[0];

        $param = ParameterInfo::fromReflection($reflectionParam);

        self::assertSame('untyped', $param->name);
        self::assertNull($param->type);
        self::assertFalse($param->hasDefault);
        self::assertFalse($param->isVariadic);
        self::assertFalse($param->isPassedByReference);
    }

    public function testFromReflectionNullable(): void
    {
        $fn = function (?string $nullable) {};
        $reflectionParam = (new ReflectionFunction($fn))->getParameters()[0];

        $param = ParameterInfo::fromReflection($reflectionParam);

        self::assertSame('nullable', $param->name);
        self::assertSame('?string', $param->type);
        self::assertFalse($param->hasDefault);
        self::assertFalse($param->isVariadic);
        self::assertFalse($param->isPassedByReference);
    }
}
