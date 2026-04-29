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
                new ParameterInfo('name', new PrimitiveType('string'), false, false, false),
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
                new ParameterInfo('a', new PrimitiveType('int'), false, false, false),
                new ParameterInfo('b', new PrimitiveType('int'), false, false, false),
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
                new ParameterInfo('arrays', new PrimitiveType('array'), false, true, false),
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
                new ParameterInfo('a', new PrimitiveType('mixed'), false, false, true),
                new ParameterInfo('b', new PrimitiveType('mixed'), false, false, true),
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
}
