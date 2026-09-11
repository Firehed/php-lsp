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
            type: new PrimitiveType('string'),
            hasDefault: true,
            defaultValue: "'hello'",
            position: 0,
            isVariadic: false,
            isPassedByReference: false,
        );

        self::assertSame('value', $param->name);
        self::assertSame('string', $param->type?->format());
        self::assertTrue($param->hasDefault);
        self::assertSame("'hello'", $param->defaultValue);
        self::assertSame(0, $param->position);
        self::assertFalse($param->isVariadic);
        self::assertFalse($param->isPassedByReference);
    }

    public function testConstructionWithNullType(): void
    {
        $param = new ParameterInfo(
            name: 'args',
            type: null,
            hasDefault: false,
            defaultValue: null,
            position: 0,
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
            type: new PrimitiveType('string'),
            hasDefault: false,
            defaultValue: null,
            position: 0,
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
            defaultValue: null,
            position: 0,
            isVariadic: false,
            isPassedByReference: false,
        );

        self::assertSame('$value', $param->format());
    }

    public function testFormatVariadic(): void
    {
        $param = new ParameterInfo(
            name: 'args',
            type: new PrimitiveType('string'),
            hasDefault: false,
            defaultValue: null,
            position: 0,
            isVariadic: true,
            isPassedByReference: false,
        );

        self::assertSame('string ...$args', $param->format());
    }

    public function testFormatWithDefaultHidden(): void
    {
        $param = new ParameterInfo(
            name: 'value',
            type: new PrimitiveType('int'),
            hasDefault: true,
            defaultValue: '10',
            position: 0,
            isVariadic: false,
            isPassedByReference: false,
        );

        self::assertSame('int $value', $param->format());
    }

    public function testFormatWithDefaultShown(): void
    {
        $param = new ParameterInfo(
            name: 'value',
            type: new PrimitiveType('int'),
            hasDefault: true,
            defaultValue: null,
            position: 0,
            isVariadic: false,
            isPassedByReference: false,
        );

        self::assertSame('int $value = ...', $param->format(showDefault: true));
    }

    public function testFormatVariadicIgnoresDefault(): void
    {
        $param = new ParameterInfo(
            name: 'args',
            type: new PrimitiveType('string'),
            hasDefault: true,
            defaultValue: '[]',
            position: 0,
            isVariadic: true,
            isPassedByReference: false,
        );

        self::assertSame('string ...$args', $param->format(showDefault: true));
    }

    public function testFormatPassedByReference(): void
    {
        $param = new ParameterInfo(
            name: 'value',
            type: new PrimitiveType('string'),
            hasDefault: false,
            defaultValue: null,
            position: 0,
            isVariadic: false,
            isPassedByReference: true,
        );

        self::assertSame('string &$value', $param->format());
    }

    public function testFormatVariadicByReference(): void
    {
        $param = new ParameterInfo(
            name: 'args',
            type: new PrimitiveType('array'),
            hasDefault: false,
            defaultValue: null,
            position: 0,
            isVariadic: true,
            isPassedByReference: true,
        );

        self::assertSame('array &...$args', $param->format());
    }

    public function testPositionField(): void
    {
        $param = new ParameterInfo(
            name: 'second',
            type: new PrimitiveType('string'),
            hasDefault: false,
            defaultValue: null,
            position: 1,
            isVariadic: false,
            isPassedByReference: false,
        );

        self::assertSame(1, $param->position);
    }

    public function testDefaultValueField(): void
    {
        $param = new ParameterInfo(
            name: 'count',
            type: new PrimitiveType('int'),
            hasDefault: true,
            defaultValue: '42',
            position: 0,
            isVariadic: false,
            isPassedByReference: false,
        );

        self::assertSame('42', $param->defaultValue);
    }

    public function testFormatWithActualDefaultValue(): void
    {
        $param = new ParameterInfo(
            name: 'count',
            type: new PrimitiveType('int'),
            hasDefault: true,
            defaultValue: '42',
            position: 0,
            isVariadic: false,
            isPassedByReference: false,
        );

        self::assertSame('int $count = 42', $param->format(showDefault: true));
    }

    public function testFormatWithNullDefaultValue(): void
    {
        $param = new ParameterInfo(
            name: 'value',
            type: new PrimitiveType('string'),
            hasDefault: true,
            defaultValue: null,
            position: 0,
            isVariadic: false,
            isPassedByReference: false,
        );

        self::assertSame('string $value = ...', $param->format(showDefault: true));
    }

    public function testResolvedSymbolMetadata(): void
    {
        $param = new ParameterInfo(
            name: 'value',
            type: new PrimitiveType('string'),
            hasDefault: false,
            defaultValue: null,
            position: 0,
            isVariadic: false,
            isPassedByReference: false,
        );

        self::assertNull($param->getDefinitionLocation(), 'a parameter has no persistent definition location');
        self::assertNull($param->getDocumentation(), 'a parameter carries no docblock');
        self::assertSame('string', $param->getType()?->format());
    }
}
