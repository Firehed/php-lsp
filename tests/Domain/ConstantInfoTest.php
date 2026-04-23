<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConstantInfo::class)]
class ConstantInfoTest extends TestCase
{
    public function testConstruction(): void
    {
        $constant = new ConstantInfo(
            name: new ConstantName('MAX_SIZE'),
            visibility: Visibility::Public,
            isFinal: true,
            type: 'int',
            docblock: '/** Maximum size */',
            file: '/path/to/file.php',
            line: 5,
            declaringClass: new ClassName(ConstantInfo::class),
        );

        self::assertSame('MAX_SIZE', $constant->name->name);
        self::assertSame(Visibility::Public, $constant->visibility);
        self::assertTrue($constant->isFinal);
        self::assertSame('int', $constant->type);
        self::assertSame('/** Maximum size */', $constant->docblock);
        self::assertSame('/path/to/file.php', $constant->file);
        self::assertSame(5, $constant->line);
        self::assertSame(ConstantInfo::class, $constant->declaringClass->fqn);
    }

    public function testFormatSimple(): void
    {
        $constant = new ConstantInfo(
            name: new ConstantName('FOO'),
            visibility: Visibility::Public,
            isFinal: false,
            type: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );

        self::assertSame('public const FOO', $constant->format());
    }

    public function testFormatWithType(): void
    {
        $constant = new ConstantInfo(
            name: new ConstantName('MAX_SIZE'),
            visibility: Visibility::Public,
            isFinal: false,
            type: 'int',
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );

        self::assertSame('public const int MAX_SIZE', $constant->format());
    }

    public function testFormatFinal(): void
    {
        $constant = new ConstantInfo(
            name: new ConstantName('VERSION'),
            visibility: Visibility::Public,
            isFinal: true,
            type: 'string',
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );

        self::assertSame('public final const string VERSION', $constant->format());
    }

    public function testFormatPrivate(): void
    {
        $constant = new ConstantInfo(
            name: new ConstantName('INTERNAL'),
            visibility: Visibility::Private,
            isFinal: false,
            type: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );

        self::assertSame('private const INTERNAL', $constant->format());
    }
}
