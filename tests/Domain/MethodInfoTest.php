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
            returnType: 'void',
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
        self::assertSame('void', $method->returnType);
        self::assertNull($method->docblock);
        self::assertSame('/path/to/file.php', $method->file);
        self::assertSame(42, $method->line);
        self::assertSame(MethodInfo::class, $method->declaringClass->fqn);
    }
}
