<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PrimitiveType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolvedFunction::class)]
class ResolvedFunctionTest extends TestCase
{
    use ResolvesFromInfoTestTrait;

    protected function createSubjectWithLocation(?string $file, ?int $line): ResolvedSymbol
    {
        return $this->createResolvedFunction(file: $file, line: $line);
    }

    protected function createSubjectWithDocblock(?string $docblock): ResolvedSymbol
    {
        return $this->createResolvedFunction(docblock: $docblock);
    }

    public function testImplementsInterfaces(): void
    {
        $resolved = $this->createResolvedFunction();

        self::assertInstanceOf(ResolvedSymbol::class, $resolved);
        self::assertInstanceOf(ResolvedCallable::class, $resolved);
    }

    public function testGetType(): void
    {
        $returnType = new PrimitiveType('string');
        $resolved = $this->createResolvedFunction(returnType: $returnType);

        self::assertSame($returnType, $resolved->getType());
    }

    public function testFormat(): void
    {
        $resolved = $this->createResolvedFunction();

        self::assertSame('function greet(): string', $resolved->format());
    }

    public function testGetParameters(): void
    {
        $params = [
            new ParameterInfo('name', new PrimitiveType('string'), false, null, 0, false, false),
        ];
        $resolved = $this->createResolvedFunction(parameters: $params);

        self::assertSame($params, $resolved->getParameters());
    }

    public function testGetReturnType(): void
    {
        $returnType = new PrimitiveType('int');
        $resolved = $this->createResolvedFunction(returnType: $returnType);

        self::assertSame($returnType, $resolved->getReturnType());
    }

    public function testGetParameterAtPosition(): void
    {
        $params = [
            new ParameterInfo('name', new PrimitiveType('string'), false, null, 0, false, false),
            new ParameterInfo('age', new PrimitiveType('int'), false, null, 1, false, false),
        ];
        $resolved = $this->createResolvedFunction(parameters: $params);

        self::assertSame($params[0], $resolved->getParameterAtPosition(0));
        self::assertSame($params[1], $resolved->getParameterAtPosition(1));
        self::assertNull($resolved->getParameterAtPosition(2));
    }

    public function testGetParameterByName(): void
    {
        $params = [
            new ParameterInfo('name', new PrimitiveType('string'), false, null, 0, false, false),
        ];
        $resolved = $this->createResolvedFunction(parameters: $params);

        self::assertSame($params[0], $resolved->getParameterByName('name'));
        self::assertNull($resolved->getParameterByName('nonexistent'));
    }

    /**
     * @param list<ParameterInfo> $parameters
     */
    private function createResolvedFunction(
        ?string $file = '/path/to/file.php',
        ?int $line = 10,
        ?string $docblock = null,
        ?PrimitiveType $returnType = null,
        array $parameters = [],
    ): ResolvedFunction {
        $funcInfo = new FunctionInfo(
            name: 'greet',
            parameters: $parameters,
            returnType: $returnType ?? new PrimitiveType('string'),
            docblock: $docblock,
            file: $file,
            line: $line,
        );

        return new ResolvedFunction($funcInfo);
    }
}
