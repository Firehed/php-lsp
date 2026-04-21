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
}
