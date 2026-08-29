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

    public function testFormatUnitEnum(): void
    {
        $case = new EnumCaseInfo(
            name: new EnumCaseName('Pending'),
            backingValue: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(ClassKind::class),
        );

        self::assertSame('case Pending', $case->format());
    }

    public function testFormatIntBackedEnum(): void
    {
        $case = new EnumCaseInfo(
            name: new EnumCaseName('Active'),
            backingValue: 1,
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(ClassKind::class),
        );

        self::assertSame('case Active = 1', $case->format());
    }

    public function testFormatStringBackedEnum(): void
    {
        $case = new EnumCaseInfo(
            name: new EnumCaseName('Draft'),
            backingValue: 'draft',
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(ClassKind::class),
        );

        self::assertSame("case Draft = 'draft'", $case->format());
    }

    public function testResolvedMemberMetadata(): void
    {
        $case = $this->makeCase();

        self::assertSame(MemberKind::EnumCase, $case->getMemberKind());
        self::assertSame('Active', $case->getName()->name);
        self::assertSame(ClassKind::class, $case->getDeclaringClass()->fqn);
        self::assertSame(ClassKind::class, $case->getType()->fqn);
        self::assertSame(Visibility::Public, $case->getVisibility());
        self::assertTrue($case->isStatic(), 'an enum case is reached on the enum');
    }

    public function testGetDefinitionLocation(): void
    {
        $case = $this->makeCase('/path/to/file.php', 8);

        $location = $case->getDefinitionLocation();

        self::assertNotNull($location);
        self::assertSame('file:///path/to/file.php', $location->uri);
        self::assertSame(7, $location->startLine);
    }

    public function testGetDefinitionLocationNullWhenFileNull(): void
    {
        self::assertNull($this->makeCase(null, 8)->getDefinitionLocation());
    }

    public function testGetDocumentation(): void
    {
        $case = $this->makeCase(docblock: "/**\n * Live status\n */");

        self::assertSame('Live status', $case->getDocumentation());
    }

    public function testGetDocumentationNullWhenNoDocblock(): void
    {
        self::assertNull($this->makeCase(docblock: null)->getDocumentation());
    }

    private function makeCase(?string $file = null, ?int $line = null, ?string $docblock = null): EnumCaseInfo
    {
        return new EnumCaseInfo(
            name: new EnumCaseName('Active'),
            backingValue: 1,
            docblock: $docblock,
            file: $file,
            line: $line,
            declaringClass: new ClassName(ClassKind::class),
        );
    }
}
