<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MethodInfo::class)]
class MethodInfoTest extends TestCase
{
    public function testConstruction(): void
    {
        $method = new MethodInfo(
            name: new MethodName('doSomething'),
            visibility: Visibility::Public,
            isStatic: false,
            isAbstract: false,
            isFinal: true,
            parameters: [],
            returnType: new PrimitiveType('void'),
            docblock: null,
            file: '/path/to/file.php',
            line: 42,
            declaringClass: new ClassName(MethodInfo::class),
        );

        self::assertSame('doSomething', $method->name->name);
        self::assertSame(Visibility::Public, $method->visibility);
        self::assertFalse($method->isStatic);
        self::assertFalse($method->isAbstract);
        self::assertTrue($method->isFinal);
        self::assertSame([], $method->parameters);
        self::assertSame('void', $method->returnType?->format());
        self::assertNull($method->docblock);
        self::assertSame('/path/to/file.php', $method->file);
        self::assertSame(42, $method->line);
        self::assertSame(MethodInfo::class, $method->declaringClass->fqn);
    }

    public function testFormatNoParamsNoReturnType(): void
    {
        $method = new MethodInfo(
            name: new MethodName('doSomething'),
            visibility: Visibility::Public,
            isStatic: false,
            isAbstract: false,
            isFinal: false,
            parameters: [],
            returnType: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );

        self::assertSame('public function doSomething()', $method->format());
    }

    public function testFormatWithReturnType(): void
    {
        $method = new MethodInfo(
            name: new MethodName('getName'),
            visibility: Visibility::Public,
            isStatic: false,
            isAbstract: false,
            isFinal: false,
            parameters: [],
            returnType: new PrimitiveType('string'),
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );

        self::assertSame('public function getName(): string', $method->format());
    }

    public function testFormatWithParameters(): void
    {
        $method = new MethodInfo(
            name: new MethodName('setName'),
            visibility: Visibility::Public,
            isStatic: false,
            isAbstract: false,
            isFinal: false,
            parameters: [
                new ParameterInfo('name', new PrimitiveType('string'), false, null, 0, false, false),
            ],
            returnType: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );

        self::assertSame('public function setName(string $name)', $method->format());
    }

    public function testFormatWithMultipleParametersAndReturnType(): void
    {
        $method = new MethodInfo(
            name: new MethodName('calculate'),
            visibility: Visibility::Public,
            isStatic: false,
            isAbstract: false,
            isFinal: false,
            parameters: [
                new ParameterInfo('a', new PrimitiveType('int'), false, null, 0, false, false),
                new ParameterInfo('b', new PrimitiveType('int'), false, null, 1, false, false),
            ],
            returnType: new PrimitiveType('int'),
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );

        self::assertSame('public function calculate(int $a, int $b): int', $method->format());
    }

    public function testFormatWithVariadicParameter(): void
    {
        $method = new MethodInfo(
            name: new MethodName('merge'),
            visibility: Visibility::Public,
            isStatic: false,
            isAbstract: false,
            isFinal: false,
            parameters: [
                new ParameterInfo('arrays', new PrimitiveType('array'), false, null, 0, true, false),
            ],
            returnType: new PrimitiveType('array'),
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );

        self::assertSame('public function merge(array ...$arrays): array', $method->format());
    }

    public function testFormatWithReferenceParameter(): void
    {
        $method = new MethodInfo(
            name: new MethodName('swap'),
            visibility: Visibility::Public,
            isStatic: true,
            isAbstract: false,
            isFinal: false,
            parameters: [
                new ParameterInfo('a', new PrimitiveType('mixed'), false, null, 0, false, true),
                new ParameterInfo('b', new PrimitiveType('mixed'), false, null, 1, false, true),
            ],
            returnType: new PrimitiveType('void'),
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );

        self::assertSame('public static function swap(mixed &$a, mixed &$b): void', $method->format());
    }

    public function testFormatAbstractMethod(): void
    {
        $method = new MethodInfo(
            name: new MethodName('handle'),
            visibility: Visibility::Protected,
            isStatic: false,
            isAbstract: true,
            isFinal: false,
            parameters: [],
            returnType: new PrimitiveType('void'),
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );

        self::assertSame('protected abstract function handle(): void', $method->format());
    }

    public function testFormatFinalMethod(): void
    {
        $method = new MethodInfo(
            name: new MethodName('getInstance'),
            visibility: Visibility::Private,
            isStatic: true,
            isAbstract: false,
            isFinal: true,
            parameters: [],
            returnType: new PrimitiveType('self'),
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );

        self::assertSame('private static final function getInstance(): self', $method->format());
    }

    public function testResolvedMemberMetadata(): void
    {
        $method = $this->makeMethod();

        self::assertSame(MemberKind::Method, $method->getMemberKind());
        self::assertSame('doSomething', $method->getName()->name);
        self::assertSame(MethodInfo::class, $method->getDeclaringClass()->fqn);
        self::assertSame(Visibility::Public, $method->getVisibility());
        self::assertFalse($method->isStatic());
    }

    public function testResolvedCallableMetadata(): void
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
        $method = $this->makeMethod(parameters: [$param]);

        self::assertSame('int', $method->getReturnType()?->format());
        self::assertSame('int', $method->getType()?->format());
        self::assertSame([$param], $method->getParameters());
        self::assertSame($param, $method->getParameterByName('value'));
        self::assertSame($param, $method->getParameterAtPosition(0));
        self::assertNull($method->getParameterByName('missing'));
        self::assertNull($method->getParameterAtPosition(1));
    }

    public function testGetDefinitionLocation(): void
    {
        $method = $this->makeMethod('/path/to/file.php', 10);

        $location = $method->getDefinitionLocation();

        self::assertNotNull($location);
        self::assertSame('file:///path/to/file.php', $location->uri);
        self::assertSame(9, $location->startLine);
    }

    public function testGetDefinitionLocationNullWhenFileNull(): void
    {
        self::assertNull($this->makeMethod(null, 10)->getDefinitionLocation());
    }

    public function testGetDocumentation(): void
    {
        $method = $this->makeMethod(docblock: "/**\n * Test description\n */");

        self::assertSame('Test description', $method->getDocumentation());
    }

    public function testGetDocumentationNullWhenNoDocblock(): void
    {
        self::assertNull($this->makeMethod(docblock: null)->getDocumentation());
    }

    /**
     * @param list<ParameterInfo> $parameters
     */
    private function makeMethod(
        ?string $file = null,
        ?int $line = null,
        ?string $docblock = null,
        array $parameters = [],
    ): MethodInfo {
        return new MethodInfo(
            name: new MethodName('doSomething'),
            visibility: Visibility::Public,
            isStatic: false,
            isAbstract: false,
            isFinal: false,
            parameters: $parameters,
            returnType: new PrimitiveType('int'),
            docblock: $docblock,
            file: $file,
            line: $line,
            declaringClass: new ClassName(MethodInfo::class),
        );
    }
}
