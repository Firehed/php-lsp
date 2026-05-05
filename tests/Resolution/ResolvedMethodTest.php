<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolvedMethod::class)]
class ResolvedMethodTest extends TestCase
{
    public function testImplementsInterfaces(): void
    {
        $resolved = $this->createResolvedMethod();

        self::assertInstanceOf(ResolvedSymbol::class, $resolved);
        self::assertInstanceOf(ResolvedMember::class, $resolved);
        self::assertInstanceOf(ResolvedCallable::class, $resolved);
    }

    public function testGetDefinitionLocation(): void
    {
        $resolved = $this->createResolvedMethod(file: '/path/to/file.php', line: 10);

        $location = $resolved->getDefinitionLocation();

        self::assertNotNull($location);
        self::assertSame('file:///path/to/file.php', $location->uri);
        self::assertSame(9, $location->startLine);
    }

    public function testGetDefinitionLocationReturnsNullWhenFileIsNull(): void
    {
        $resolved = $this->createResolvedMethod(file: null, line: 10);

        self::assertNull($resolved->getDefinitionLocation());
    }

    public function testGetDocumentation(): void
    {
        $resolved = $this->createResolvedMethod(docblock: "/**\n * Does something\n */");

        self::assertSame('Does something', $resolved->getDocumentation());
    }

    public function testGetDocumentationReturnsNullWhenNoDocblock(): void
    {
        $resolved = $this->createResolvedMethod(docblock: null);

        self::assertNull($resolved->getDocumentation());
    }

    public function testGetType(): void
    {
        $returnType = new PrimitiveType('string');
        $resolved = $this->createResolvedMethod(returnType: $returnType);

        self::assertSame($returnType, $resolved->getType());
    }

    public function testGetTypeReturnsNullWhenNoReturnType(): void
    {
        $resolved = $this->createResolvedMethodWithNullReturnType();

        self::assertNull($resolved->getType());
    }

    public function testFormat(): void
    {
        $resolved = $this->createResolvedMethod();

        self::assertSame('public function doSomething(): string', $resolved->format());
    }

    public function testGetDeclaringClass(): void
    {
        $className = new ClassName(\stdClass::class);
        $resolved = $this->createResolvedMethod(declaringClass: $className);

        self::assertSame($className, $resolved->getDeclaringClass());
    }

    public function testGetVisibility(): void
    {
        $resolved = $this->createResolvedMethod(visibility: Visibility::Protected);

        self::assertSame(Visibility::Protected, $resolved->getVisibility());
    }

    public function testIsStatic(): void
    {
        $resolved = $this->createResolvedMethod(isStatic: true);

        self::assertTrue($resolved->isStatic());
    }

    public function testIsStaticFalse(): void
    {
        $resolved = $this->createResolvedMethod(isStatic: false);

        self::assertFalse($resolved->isStatic());
    }

    public function testGetParameters(): void
    {
        $params = [
            new ParameterInfo('name', new PrimitiveType('string'), false, null, 0, false, false),
            new ParameterInfo('count', new PrimitiveType('int'), false, null, 1, false, false),
        ];
        $resolved = $this->createResolvedMethod(parameters: $params);

        self::assertSame($params, $resolved->getParameters());
    }

    public function testGetReturnType(): void
    {
        $returnType = new PrimitiveType('int');
        $resolved = $this->createResolvedMethod(returnType: $returnType);

        self::assertSame($returnType, $resolved->getReturnType());
    }

    public function testGetParameterAtPosition(): void
    {
        $params = [
            new ParameterInfo('name', new PrimitiveType('string'), false, null, 0, false, false),
            new ParameterInfo('count', new PrimitiveType('int'), false, null, 1, false, false),
        ];
        $resolved = $this->createResolvedMethod(parameters: $params);

        self::assertSame($params[0], $resolved->getParameterAtPosition(0));
        self::assertSame($params[1], $resolved->getParameterAtPosition(1));
        self::assertNull($resolved->getParameterAtPosition(2));
    }

    public function testGetParameterByName(): void
    {
        $params = [
            new ParameterInfo('name', new PrimitiveType('string'), false, null, 0, false, false),
            new ParameterInfo('count', new PrimitiveType('int'), false, null, 1, false, false),
        ];
        $resolved = $this->createResolvedMethod(parameters: $params);

        self::assertSame($params[0], $resolved->getParameterByName('name'));
        self::assertSame($params[1], $resolved->getParameterByName('count'));
        self::assertNull($resolved->getParameterByName('nonexistent'));
    }

    /**
     * @param list<ParameterInfo> $parameters
     */
    private function createResolvedMethod(
        ?string $file = '/path/to/file.php',
        ?int $line = 10,
        ?string $docblock = null,
        ?PrimitiveType $returnType = null,
        ?ClassName $declaringClass = null,
        Visibility $visibility = Visibility::Public,
        bool $isStatic = false,
        array $parameters = [],
    ): ResolvedMethod {
        $methodInfo = new MethodInfo(
            name: new MethodName('doSomething'),
            visibility: $visibility,
            isStatic: $isStatic,
            isAbstract: false,
            isFinal: false,
            parameters: $parameters,
            returnType: $returnType ?? new PrimitiveType('string'),
            docblock: $docblock,
            file: $file,
            line: $line,
            declaringClass: $declaringClass ?? new ClassName(\stdClass::class),
        );

        return new ResolvedMethod($methodInfo);
    }

    private function createResolvedMethodWithNullReturnType(): ResolvedMethod
    {
        $methodInfo = new MethodInfo(
            name: new MethodName('doSomething'),
            visibility: Visibility::Public,
            isStatic: false,
            isAbstract: false,
            isFinal: false,
            parameters: [],
            returnType: null,
            docblock: null,
            file: '/path/to/file.php',
            line: 10,
            declaringClass: new ClassName(\stdClass::class),
        );

        return new ResolvedMethod($methodInfo);
    }
}
