<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParameterInfo::class)]
class ParameterInfoTest extends TestCase
{
    public function testLateBindingBindsTheType(): void
    {
        $declaring = ClasslikeName::fromFullyQualified('App\\Declaring');
        $param = new ParameterInfo('other', new LateStaticType(LateBindingKeyword::Self), "'x'", 2, true, true);

        $bound = $param->resolveLateBound(new LateBinding($declaring, $declaring, null));

        self::assertSame('App\\Declaring', $bound->type?->format(), 'the type is bound');
        self::assertSame(
            ['other', "'x'", 2, true, true],
            [$bound->name, $bound->defaultValue, $bound->position, $bound->isVariadic, $bound->isPassedByReference],
            'the rest is kept',
        );
    }

    public function testLateBindingKeepsAParameterWithNothingToBind(): void
    {
        $declaring = ClasslikeName::fromFullyQualified('App\\Declaring');
        $param = new ParameterInfo('count', PrimitiveType::Int, null, 0, false, false);

        self::assertSame($param, $param->resolveLateBound(new LateBinding($declaring, $declaring, null)));
    }

    public function testConstruction(): void
    {
        $param = new ParameterInfo(
            name: 'value',
            type: PrimitiveType::String,
            defaultValue: "'hello'",
            position: 0,
            isVariadic: false,
            isPassedByReference: false,
        );

        self::assertSame('value', $param->name);
        self::assertSame('string', $param->type?->format());
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
            type: PrimitiveType::String,
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
            type: PrimitiveType::String,
            defaultValue: null,
            position: 0,
            isVariadic: true,
            isPassedByReference: false,
        );

        self::assertSame('string ...$args', $param->format());
    }

    public function testFormatVariadicIgnoresDefault(): void
    {
        $param = new ParameterInfo(
            name: 'args',
            type: PrimitiveType::String,
            defaultValue: '[]',
            position: 0,
            isVariadic: true,
            isPassedByReference: false,
        );

        self::assertSame('string ...$args', $param->format());
    }

    public function testFormatPassedByReference(): void
    {
        $param = new ParameterInfo(
            name: 'value',
            type: PrimitiveType::String,
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
            type: PrimitiveType::Array,
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
            type: PrimitiveType::String,
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
            type: PrimitiveType::Int,
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
            type: PrimitiveType::Int,
            defaultValue: '42',
            position: 0,
            isVariadic: false,
            isPassedByReference: false,
        );

        self::assertSame('int $count = 42', $param->format(), 'a parameter always shows its default');
    }

    public function testResolvedSymbolMetadata(): void
    {
        $param = new ParameterInfo(
            name: 'value',
            type: PrimitiveType::String,
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
