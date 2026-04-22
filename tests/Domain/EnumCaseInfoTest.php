<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnumCaseInfo::class)]
class EnumCaseInfoTest extends TestCase
{
    public function testConstruction(): void
    {
        $case = new EnumCaseInfo(
            name: new EnumCaseName('Active'),
            backingValue: 1,
            docblock: null,
            file: '/path/to/file.php',
            line: 8,
            declaringClass: new ClassName(ClassKind::class),
        );

        self::assertSame('Active', $case->name->name);
        self::assertSame(1, $case->backingValue);
        self::assertNull($case->docblock);
        self::assertSame('/path/to/file.php', $case->file);
        self::assertSame(8, $case->line);
        self::assertSame(ClassKind::class, $case->declaringClass->fqn);
    }
}
