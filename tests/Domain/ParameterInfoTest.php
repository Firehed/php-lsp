<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\Int_;
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

    public function testFromNodeSimple(): void
    {
        $node = new Param(
            var: new Variable('name'),
            default: null,
            type: new Identifier('string'),
        );

        $param = ParameterInfo::fromNode($node, 0);

        self::assertNotNull($param);
        self::assertSame('name', $param->name);
        self::assertSame('string', $param->type?->format());
        self::assertFalse($param->hasDefault);
        self::assertFalse($param->isVariadic);
        self::assertFalse($param->isPassedByReference);
        self::assertSame(0, $param->position);
        self::assertNull($param->defaultValue);
    }

    public function testFromReflectionSimple(): void
    {
        $fn = function (string $value) {
        };
        $reflectionParam = (new ReflectionFunction($fn))->getParameters()[0];

        $param = ParameterInfo::fromReflection($reflectionParam);

        self::assertSame('value', $param->name);
        self::assertSame('string', $param->type?->format());
        self::assertFalse($param->hasDefault);
        self::assertFalse($param->isVariadic);
        self::assertFalse($param->isPassedByReference);
    }

    public function testFromNodeWithDefault(): void
    {
        $node = new Param(
            var: new Variable('count'),
            default: new Int_(0),
            type: new Identifier('int'),
        );

        $param = ParameterInfo::fromNode($node, 0);

        self::assertNotNull($param);
        self::assertSame('count', $param->name);
        self::assertTrue($param->hasDefault);
        self::assertSame('0', $param->defaultValue);
    }

    public function testFromNodeVariadic(): void
    {
        $node = new Param(
            var: new Variable('args'),
            default: null,
            type: new Identifier('string'),
            variadic: true,
        );

        $param = ParameterInfo::fromNode($node, 0);

        self::assertNotNull($param);
        self::assertTrue($param->isVariadic);
    }

    public function testFromNodeByReference(): void
    {
        $node = new Param(
            var: new Variable('value'),
            default: null,
            type: new Identifier('int'),
            byRef: true,
        );

        $param = ParameterInfo::fromNode($node, 0);

        self::assertNotNull($param);
        self::assertTrue($param->isPassedByReference);
    }

    public function testFromNodeNoType(): void
    {
        $node = new Param(
            var: new Variable('data'),
            default: null,
            type: null,
        );

        $param = ParameterInfo::fromNode($node, 0);

        self::assertNotNull($param);
        self::assertNull($param->type);
    }

    public function testFromNodeReturnsNullForNonStringVariableName(): void
    {
        $var = new Variable(new Int_(0));
        $node = new Param(
            var: $var,
            default: null,
            type: null,
        );

        $param = ParameterInfo::fromNode($node, 0);

        self::assertNull($param);
    }

    public function testFromReflectionWithDefault(): void
    {
        $fn = function (int $count = 10) {
        };
        $reflectionParam = (new ReflectionFunction($fn))->getParameters()[0];

        $param = ParameterInfo::fromReflection($reflectionParam);

        self::assertSame('count', $param->name);
        self::assertSame('int', $param->type?->format());
        self::assertTrue($param->hasDefault);
        self::assertFalse($param->isVariadic);
        self::assertFalse($param->isPassedByReference);
    }

    public function testFromReflectionVariadic(): void
    {
        $fn = function (string ...$args) {
        };
        $reflectionParam = (new ReflectionFunction($fn))->getParameters()[0];

        $param = ParameterInfo::fromReflection($reflectionParam);

        self::assertSame('args', $param->name);
        self::assertSame('string', $param->type?->format());
        self::assertFalse($param->hasDefault);
        self::assertTrue($param->isVariadic);
        self::assertFalse($param->isPassedByReference);
    }

    public function testFromReflectionByReference(): void
    {
        $fn = function (array &$data) {
        };
        $reflectionParam = (new ReflectionFunction($fn))->getParameters()[0];

        $param = ParameterInfo::fromReflection($reflectionParam);

        self::assertSame('data', $param->name);
        self::assertSame('array', $param->type?->format());
        self::assertFalse($param->hasDefault);
        self::assertFalse($param->isVariadic);
        self::assertTrue($param->isPassedByReference);
    }

    public function testFromReflectionNoType(): void
    {
        $fn = function ($untyped) {
        };
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
        $fn = function (?string $nullable) {
        };
        $reflectionParam = (new ReflectionFunction($fn))->getParameters()[0];

        $param = ParameterInfo::fromReflection($reflectionParam);

        self::assertSame('nullable', $param->name);
        self::assertSame('?string', $param->type?->format());
        self::assertFalse($param->hasDefault);
        self::assertFalse($param->isVariadic);
        self::assertFalse($param->isPassedByReference);
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

    public function testFromNodeExtractsDefaultValue(): void
    {
        $node = new Param(
            var: new Variable('count'),
            default: new Int_(42),
            type: new Identifier('int'),
        );

        $param = ParameterInfo::fromNode($node, 0);

        self::assertNotNull($param);
        self::assertSame('42', $param->defaultValue);
    }

    public function testFromNodeWithPosition(): void
    {
        $node = new Param(
            var: new Variable('name'),
            default: null,
            type: new Identifier('string'),
        );

        $param = ParameterInfo::fromNode($node, 2);

        self::assertNotNull($param);
        self::assertSame(2, $param->position);
    }

    public function testFromReflectionExtractsPosition(): void
    {
        $fn = function (string $first, int $second, bool $third) {
        };
        $params = (new ReflectionFunction($fn))->getParameters();

        $first = ParameterInfo::fromReflection($params[0]);
        $second = ParameterInfo::fromReflection($params[1]);
        $third = ParameterInfo::fromReflection($params[2]);

        self::assertSame(0, $first->position);
        self::assertSame(1, $second->position);
        self::assertSame(2, $third->position);
    }

    public function testFromReflectionExtractsDefaultValue(): void
    {
        $fn = function (int $count = 10) {
        };
        $reflectionParam = (new ReflectionFunction($fn))->getParameters()[0];

        $param = ParameterInfo::fromReflection($reflectionParam);

        self::assertSame('10', $param->defaultValue);
    }

    public function testFromReflectionWithStringDefault(): void
    {
        $fn = function (string $name = 'default') {
        };
        $reflectionParam = (new ReflectionFunction($fn))->getParameters()[0];

        $param = ParameterInfo::fromReflection($reflectionParam);

        self::assertSame("'default'", $param->defaultValue);
    }

    public function testFromReflectionWithNullDefault(): void
    {
        $fn = function (?string $value = null) {
        };
        $reflectionParam = (new ReflectionFunction($fn))->getParameters()[0];

        $param = ParameterInfo::fromReflection($reflectionParam);

        self::assertSame('null', $param->defaultValue);
    }

    public function testFromReflectionWithArrayDefault(): void
    {
        $fn = function (array $items = []) {
        };
        $reflectionParam = (new ReflectionFunction($fn))->getParameters()[0];

        $param = ParameterInfo::fromReflection($reflectionParam);

        self::assertSame('[]', $param->defaultValue);
    }
}
