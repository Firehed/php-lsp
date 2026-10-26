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
            name: FunctionName::fromFullyQualified('myFunction'),
            parameters: [],
            returnType: PrimitiveType::Void,
            docblock: '/** Does something */',
            file: '/path/to/file.php',
            line: 10,
        );

        self::assertSame('myFunction', $func->name->qualifiedName->fullyQualifiedName());
        self::assertSame([], $func->parameters);
        self::assertSame('void', $func->returnType?->format());
        self::assertSame('/** Does something */', $func->docblock);
        self::assertSame('/path/to/file.php', $func->file);
        self::assertSame(10, $func->line);
    }

    public function testFormatNoParamsNoReturnType(): void
    {
        $func = new FunctionInfo(
            name: FunctionName::fromFullyQualified('doSomething'),
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
            name: FunctionName::fromFullyQualified('getName'),
            parameters: [],
            returnType: PrimitiveType::String,
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('function getName(): string', $func->format());
    }

    public function testFormatWithParameters(): void
    {
        $func = new FunctionInfo(
            name: FunctionName::fromFullyQualified('greet'),
            parameters: [
                new ParameterInfo('name', PrimitiveType::String, null, 0, false, false),
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
            name: FunctionName::fromFullyQualified('add'),
            parameters: [
                new ParameterInfo('a', PrimitiveType::Int, null, 0, false, false),
                new ParameterInfo('b', PrimitiveType::Int, null, 1, false, false),
            ],
            returnType: PrimitiveType::Int,
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('function add(int $a, int $b): int', $func->format());
    }

    public function testFormatShowsDefaults(): void
    {
        $func = new FunctionInfo(
            name: FunctionName::fromFullyQualified('greet'),
            parameters: [
                new ParameterInfo('name', PrimitiveType::String, "'world'", 0, false, false),
            ],
            returnType: null,
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame(
            "function greet(string \$name = 'world')",
            $func->format(),
            'a function signature shows defaults, as a method signature does',
        );
    }

    public function testFormatWithVariadicParameter(): void
    {
        $func = new FunctionInfo(
            name: FunctionName::fromFullyQualified('sum'),
            parameters: [
                new ParameterInfo('numbers', PrimitiveType::Int, null, 0, true, false),
            ],
            returnType: PrimitiveType::Int,
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('function sum(int ...$numbers): int', $func->format());
    }

    public function testResolvedCallableMetadata(): void
    {
        $func = $this->makeSubject();

        self::assertSame('int', $func->getType()?->format());
        self::assertSame([], $func->getParameters());
        self::assertNull($func->getParameterByName('missing'));
    }

    protected function makeSubject(?string $file = null, ?int $line = null, ?string $docblock = null): FunctionInfo
    {
        return new FunctionInfo(
            name: FunctionName::fromFullyQualified('myFunction'),
            parameters: [],
            returnType: PrimitiveType::Int,
            docblock: $docblock,
            file: $file,
            line: $line,
        );
    }
}
