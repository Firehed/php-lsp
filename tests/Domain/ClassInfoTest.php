<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassInfo::class)]
class ClassInfoTest extends TestCase
{
    public function testConstruction(): void
    {
        $class = new ClassInfo(
            name: new ClassName('App\\MyClass'),
            kind: ClassKind::Class_,
            isAbstract: false,
            isFinal: true,
            isReadonly: true,
            parent: new ClassName('App\\BaseClass'),
            methods: [],
            properties: [],
            constants: [],
            enumCases: [],
            docblock: '/** My class */',
            file: '/path/to/file.php',
            line: 3,
        );

        self::assertSame('App\\MyClass', $class->name->fqn);
        self::assertSame(ClassKind::Class_, $class->kind);
        self::assertFalse($class->isAbstract);
        self::assertTrue($class->isFinal);
        self::assertTrue($class->isReadonly);
        self::assertSame('App\\BaseClass', $class->parent?->fqn);
        self::assertSame([], $class->methods);
        self::assertSame([], $class->properties);
        self::assertSame([], $class->constants);
        self::assertSame([], $class->enumCases);
        self::assertSame('/** My class */', $class->docblock);
        self::assertSame('/path/to/file.php', $class->file);
        self::assertSame(3, $class->line);
    }

    public function testConstructionWithNullParent(): void
    {
        $class = new ClassInfo(
            name: new ClassName('App\\MyInterface'),
            kind: ClassKind::Interface_,
            isAbstract: false,
            isFinal: false,
            isReadonly: false,
            parent: null,
            methods: [],
            properties: [],
            constants: [],
            enumCases: [],
            docblock: null,
            file: null,
            line: null,
        );

        self::assertNull($class->parent);
        self::assertNull($class->docblock);
        self::assertNull($class->file);
        self::assertNull($class->line);
    }
}
