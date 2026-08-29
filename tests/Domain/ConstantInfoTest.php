<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConstantInfo::class)]
#[CoversClass(PrimitiveType::class)]
class ConstantInfoTest extends TestCase
{
    public function testConstruction(): void
    {
        $constant = new ConstantInfo(
            name: new ConstantName('MAX_SIZE'),
            visibility: Visibility::Public,
            isFinal: true,
            type: new PrimitiveType('int'),
            docblock: '/** Maximum size */',
            file: '/path/to/file.php',
            line: 5,
            declaringClass: new ClassName(ConstantInfo::class),
        );

        self::assertSame('MAX_SIZE', $constant->name->name);
        self::assertSame(Visibility::Public, $constant->visibility);
        self::assertTrue($constant->isFinal);
        self::assertSame('int', $constant->type?->format());
        self::assertSame('/** Maximum size */', $constant->docblock);
        self::assertSame('/path/to/file.php', $constant->file);
        self::assertSame(5, $constant->line);
        self::assertNotNull($constant->declaringClass, 'class constant has a declaring class');
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
            type: new PrimitiveType('int'),
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
            type: new PrimitiveType('string'),
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

    public function testFormatGlobalConstant(): void
    {
        $constant = new ConstantInfo(
            name: new ConstantName('DEBUG'),
            visibility: Visibility::Public,
            isFinal: true,
            type: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: null,
        );

        self::assertSame('const DEBUG', $constant->format(), 'global constants omit visibility');
    }

    public function testFormatGlobalConstantWithType(): void
    {
        $constant = new ConstantInfo(
            name: new ConstantName('MAX_SIZE'),
            visibility: Visibility::Public,
            isFinal: true,
            type: new PrimitiveType('int'),
            docblock: null,
            file: null,
            line: null,
            declaringClass: null,
        );

        self::assertSame('const int MAX_SIZE', $constant->format(), 'global constants show type after const');
    }

    public function testResolvedMemberMetadata(): void
    {
        $constant = $this->makeClassConstant();

        self::assertSame(MemberKind::Constant, $constant->getMemberKind());
        self::assertSame('MAX_SIZE', $constant->getName()->name);
        self::assertSame(ConstantInfo::class, $constant->getDeclaringClass()->fqn);
        self::assertSame('int', $constant->getType()?->format());
        self::assertSame(Visibility::Public, $constant->getVisibility());
        self::assertTrue($constant->isStatic(), 'a class constant is reached on the class');
    }

    public function testGetDeclaringClassAssertsOnGlobalConstant(): void
    {
        $globalConstant = new ConstantInfo(
            name: new ConstantName('DEBUG'),
            visibility: Visibility::Public,
            isFinal: true,
            type: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: null,
        );

        $this->expectException(\AssertionError::class);
        $globalConstant->getDeclaringClass();
    }

    public function testGetDefinitionLocation(): void
    {
        $constant = $this->makeClassConstant('/path/to/file.php', 5);

        $location = $constant->getDefinitionLocation();

        self::assertNotNull($location);
        self::assertSame('file:///path/to/file.php', $location->uri);
        self::assertSame(4, $location->startLine);
    }

    public function testGetDefinitionLocationNullWhenFileNull(): void
    {
        self::assertNull($this->makeClassConstant(null, 5)->getDefinitionLocation());
    }

    public function testGetDocumentation(): void
    {
        $constant = $this->makeClassConstant(docblock: "/**\n * The upper bound\n */");

        self::assertSame('The upper bound', $constant->getDocumentation());
    }

    public function testGetDocumentationNullWhenNoDocblock(): void
    {
        self::assertNull($this->makeClassConstant(docblock: null)->getDocumentation());
    }

    private function makeClassConstant(
        ?string $file = null,
        ?int $line = null,
        ?string $docblock = null,
    ): ConstantInfo {
        return new ConstantInfo(
            name: new ConstantName('MAX_SIZE'),
            visibility: Visibility::Public,
            isFinal: true,
            type: new PrimitiveType('int'),
            docblock: $docblock,
            file: $file,
            line: $line,
            declaringClass: new ClassName(ConstantInfo::class),
        );
    }
}
