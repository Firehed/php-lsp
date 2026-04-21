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
}
