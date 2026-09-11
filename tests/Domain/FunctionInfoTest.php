<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use Firehed\PhpLsp\Tests\Domain\HasSymbolLocationTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FunctionInfo::class)]
class FunctionInfoTest extends TestCase
{
    use HasSymbolLocationTestTrait;

    public function testConstruction(): void
    {
        $func = new FunctionInfo(
            name: 'myFunction',
            parameters: [],
            returnType: new PrimitiveType('void'),
            docblock: '/** Does something */',
            file: '/path/to/file.php',
            line: 10,
        );

        self::assertSame('myFunction', $func->name);
        self::assertSame([], $func->parameters);
        self::assertSame('void', $func->returnType?->format());
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
            returnType: new PrimitiveType('string'),
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
                new ParameterInfo('name', new PrimitiveType('string'), false, null, 0, false, false),
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
                new ParameterInfo('a', new PrimitiveType('int'), false, null, 0, false, false),
                new ParameterInfo('b', new PrimitiveType('int'), false, null, 1, false, false),
            ],
            returnType: new PrimitiveType('int'),
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
                new ParameterInfo('numbers', new PrimitiveType('int'), false, null, 0, true, false),
            ],
            returnType: new PrimitiveType('int'),
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('function sum(int ...$numbers): int', $func->format());
    }

    public function testResolvedCallableMetadata(): void
    {
        $func = $this->makeSubject();

        self::assertSame('int', $func->getReturnType()?->format());
        self::assertSame('int', $func->getType()?->format());
        self::assertSame([], $func->getParameters());
        self::assertNull($func->getParameterByName('missing'));
        self::assertNull($func->getParameterAtPosition(0));
    }

    protected function makeSubject(?string $file = null, ?int $line = null, ?string $docblock = null): FunctionInfo
    {
        return new FunctionInfo(
            name: 'myFunction',
            parameters: [],
            returnType: new PrimitiveType('int'),
            docblock: $docblock,
            file: $file,
            line: $line,
        );
    }
}
