<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FunctionInfo::class)]
class FunctionInfoTest extends TestCase
{
    public function testConstruction(): void
    {
        $func = new FunctionInfo(
            name: 'myFunction',
            parameters: [],
            returnType: 'void',
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
                new ParameterInfo('name', 'string', false, false, false),
            ],
            returnType: null,
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
                new ParameterInfo('a', 'int', false, false, false),
                new ParameterInfo('b', 'int', false, false, false),
            ],
            returnType: 'int',
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
                new ParameterInfo('numbers', 'int', false, true, false),
            ],
            returnType: 'int',
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('function sum(int ...$numbers): int', $func->format());
    }
}
