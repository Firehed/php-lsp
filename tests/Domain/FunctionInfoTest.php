<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PhpParser\Comment\Doc;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

#[CoversClass(FunctionInfo::class)]
class FunctionInfoTest extends TestCase
{
    public function testConstruction(): void
    {
        $func = new FunctionInfo(
            name: 'myFunction',
            parameters: [],
            returnType: 'void',
            returnTypeInfo: null,
            docblock: '/** Does something */',
            file: '/path/to/file.php',
            line: 10,
        );

        self::assertSame('myFunction', $func->name);
        self::assertSame([], $func->parameters);
        self::assertSame('void', $func->returnType);
        self::assertSame('/** Does something */', $func->docblock);
        self::assertSame('/path/to/file.php', $func->file);
        self::assertSame(10, $func->line);
    }

    public function testFormatNoParamsNoReturnType(): void
    {
        $func = new FunctionInfo(
            name: 'doSomething',
            parameters: [],
            returnType: null,
            returnTypeInfo: null,
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('function doSomething()', $func->format());
    }

    public function testFormatWithReturnType(): void
    {
        $func = new FunctionInfo(
            name: 'getName',
            parameters: [],
            returnType: 'string',
            returnTypeInfo: null,
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('function getName(): string', $func->format());
    }

    public function testFormatWithParameters(): void
    {
        $func = new FunctionInfo(
            name: 'greet',
            parameters: [
                new ParameterInfo('name', 'string', null, false, false, false),
            ],
            returnType: null,
            returnTypeInfo: null,
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('function greet(string $name)', $func->format());
    }

    public function testFormatWithMultipleParametersAndReturnType(): void
    {
        $func = new FunctionInfo(
            name: 'add',
            parameters: [
                new ParameterInfo('a', 'int', null, false, false, false),
                new ParameterInfo('b', 'int', null, false, false, false),
            ],
            returnType: 'int',
            returnTypeInfo: null,
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('function add(int $a, int $b): int', $func->format());
    }

    public function testFormatWithVariadicParameter(): void
    {
        $func = new FunctionInfo(
            name: 'sum',
            parameters: [
                new ParameterInfo('numbers', 'int', null, false, true, false),
            ],
            returnType: 'int',
            returnTypeInfo: null,
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('function sum(int ...$numbers): int', $func->format());
    }

    public function testFromNodeSimple(): void
    {
        $node = new Stmt\Function_(
            name: new Identifier('myFunc'),
            subNodes: [
                'params' => [],
                'returnType' => null,
            ],
        );

        $func = FunctionInfo::fromNode($node);

        self::assertSame('myFunc', $func->name);
        self::assertSame([], $func->parameters);
        self::assertNull($func->returnType);
        self::assertNull($func->docblock);
    }

    public function testFromNodeWithParams(): void
    {
        $node = new Stmt\Function_(
            name: new Identifier('greet'),
            subNodes: [
                'params' => [
                    new Param(
                        var: new Variable('name'),
                        type: new Identifier('string'),
                    ),
                ],
                'returnType' => new Identifier('void'),
            ],
        );

        $func = FunctionInfo::fromNode($node);

        self::assertSame('greet', $func->name);
        self::assertCount(1, $func->parameters);
        self::assertSame('name', $func->parameters[0]->name);
        self::assertSame('string', $func->parameters[0]->type);
        self::assertSame('void', $func->returnType);
    }

    public function testFromNodeWithMultipleParams(): void
    {
        $node = new Stmt\Function_(
            name: new Identifier('add'),
            subNodes: [
                'params' => [
                    new Param(
                        var: new Variable('a'),
                        type: new Identifier('int'),
                    ),
                    new Param(
                        var: new Variable('b'),
                        type: new Identifier('int'),
                    ),
                ],
                'returnType' => new Identifier('int'),
            ],
        );

        $func = FunctionInfo::fromNode($node);

        self::assertSame('add', $func->name);
        self::assertCount(2, $func->parameters);
        self::assertSame('int', $func->returnType);
    }

    public function testFromNodeWithDocblock(): void
    {
        $node = new Stmt\Function_(
            name: new Identifier('documented'),
            subNodes: [
                'params' => [],
                'returnType' => null,
            ],
            attributes: [
                'comments' => [new Doc('/** This function does something. */')],
            ],
        );

        $func = FunctionInfo::fromNode($node);

        self::assertSame('/** This function does something. */', $func->docblock);
    }

    public function testFromReflectionSimple(): void
    {
        $fn = function () {
        };
        $reflection = new ReflectionFunction($fn);

        $func = FunctionInfo::fromReflection($reflection);

        self::assertSame([], $func->parameters);
        self::assertNull($func->returnType);
    }

    public function testFromReflectionWithParams(): void
    {
        $fn = function (string $name): void {
        };
        $reflection = new ReflectionFunction($fn);

        $func = FunctionInfo::fromReflection($reflection);

        self::assertCount(1, $func->parameters);
        self::assertSame('name', $func->parameters[0]->name);
        self::assertSame('string', $func->parameters[0]->type);
        self::assertSame('void', $func->returnType);
    }

    public function testFromReflectionWithMultipleParams(): void
    {
        $fn = function (int $a, int $b): int {
            return $a + $b;
        };
        $reflection = new ReflectionFunction($fn);

        $func = FunctionInfo::fromReflection($reflection);

        self::assertCount(2, $func->parameters);
        self::assertSame('a', $func->parameters[0]->name);
        self::assertSame('b', $func->parameters[1]->name);
        self::assertSame('int', $func->returnType);
    }

    public function testFromReflectionVariadic(): void
    {
        $fn = function (int ...$numbers): int {
            return array_sum($numbers);
        };
        $reflection = new ReflectionFunction($fn);

        $func = FunctionInfo::fromReflection($reflection);

        self::assertCount(1, $func->parameters);
        self::assertTrue($func->parameters[0]->isVariadic);
        self::assertSame('int', $func->returnType);
    }

    public function testFromReflectionWithDocblock(): void
    {
        require_once __DIR__ . '/Fixtures/documented_function.php';
        $reflection = new ReflectionFunction('testDocumentedFunction');

        $func = FunctionInfo::fromReflection($reflection);

        self::assertSame("/**\n * A test function with documentation.\n */", $func->docblock);
    }
}
