<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PropertyInfo::class)]
class PropertyInfoTest extends TestCase
{
    public function testConstruction(): void
    {
        $property = new PropertyInfo(
            name: new PropertyName('value'),
            visibility: Visibility::Private,
            isStatic: false,
            isReadonly: true,
            isPromoted: true,
            type: 'string',
            typeInfo: new PrimitiveType('string'),
            docblock: null,
            file: '/path/to/file.php',
            line: 10,
            declaringClass: new ClassName(PropertyInfo::class),
        );

        self::assertSame('value', $property->name->name);
        self::assertSame(Visibility::Private, $property->visibility);
        self::assertFalse($property->isStatic);
        self::assertTrue($property->isReadonly);
        self::assertTrue($property->isPromoted);
        self::assertSame('string', $property->type);
        self::assertNull($property->docblock);
        self::assertSame('/path/to/file.php', $property->file);
        self::assertSame(10, $property->line);
        self::assertSame(PropertyInfo::class, $property->declaringClass->fqn);
    }

    public function testFormatSimple(): void
    {
        $property = new PropertyInfo(
            name: new PropertyName('name'),
            visibility: Visibility::Public,
            isStatic: false,
            isReadonly: false,
            isPromoted: false,
            type: 'string',
            typeInfo: new PrimitiveType('string'),
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );

        self::assertSame('public string $name', $property->format());
    }

    public function testFormatStatic(): void
    {
        $property = new PropertyInfo(
            name: new PropertyName('instance'),
            visibility: Visibility::Private,
            isStatic: true,
            isReadonly: false,
            isPromoted: false,
            type: 'self',
            typeInfo: new PrimitiveType('self'),
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );

        self::assertSame('private static self $instance', $property->format());
    }

    public function testFormatReadonly(): void
    {
        $property = new PropertyInfo(
            name: new PropertyName('id'),
            visibility: Visibility::Public,
            isStatic: false,
            isReadonly: true,
            isPromoted: false,
            type: 'int',
            typeInfo: new PrimitiveType('int'),
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );

        self::assertSame('public readonly int $id', $property->format());
    }

    public function testFormatNoType(): void
    {
        $property = new PropertyInfo(
            name: new PropertyName('data'),
            visibility: Visibility::Protected,
            isStatic: false,
            isReadonly: false,
            isPromoted: false,
            type: null,
            typeInfo: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );

        self::assertSame('protected $data', $property->format());
    }

    public function testFormatAllModifiers(): void
    {
        $property = new PropertyInfo(
            name: new PropertyName('cache'),
            visibility: Visibility::Private,
            isStatic: true,
            isReadonly: true,
            isPromoted: false,
            type: 'array',
            typeInfo: new PrimitiveType('array'),
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );

        self::assertSame('private static readonly array $cache', $property->format());
    }
}
