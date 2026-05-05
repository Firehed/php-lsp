<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\EnumCaseInfo;
use Firehed\PhpLsp\Domain\EnumCaseName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolvedEnumCase::class)]
class ResolvedEnumCaseTest extends TestCase
{
    public function testImplementsResolvedSymbol(): void
    {
        $resolved = $this->createResolvedEnumCase();

        self::assertInstanceOf(ResolvedSymbol::class, $resolved);
    }

    public function testGetDefinitionLocation(): void
    {
        $resolved = $this->createResolvedEnumCase();

        $location = $resolved->getDefinitionLocation();

        self::assertNotNull($location);
        self::assertSame('file:///path/to/file.php', $location->uri);
    }

    public function testGetDocumentation(): void
    {
        $resolved = $this->createResolvedEnumCase(docblock: "/**\n * Active status\n */");

        self::assertSame('Active status', $resolved->getDocumentation());
    }

    public function testGetDocumentationReturnsNullWhenNoDocblock(): void
    {
        $resolved = $this->createResolvedEnumCase(docblock: null);

        self::assertNull($resolved->getDocumentation());
    }

    public function testGetTypeReturnsDeclaringEnum(): void
    {
        $enumName = new ClassName(ClassKind::class);
        $resolved = $this->createResolvedEnumCase(declaringClass: $enumName);

        $type = $resolved->getType();

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(ClassKind::class, $type->fqn);
    }

    public function testFormat(): void
    {
        $resolved = $this->createResolvedEnumCase();

        self::assertSame('case Active', $resolved->format());
    }

    public function testGetDeclaringClass(): void
    {
        $enumName = new ClassName(ClassKind::class);
        $resolved = $this->createResolvedEnumCase(declaringClass: $enumName);

        self::assertSame($enumName, $resolved->getDeclaringClass());
    }

    private function createResolvedEnumCase(
        ?string $docblock = null,
        ?ClassName $declaringClass = null,
    ): ResolvedEnumCase {
        $caseInfo = new EnumCaseInfo(
            name: new EnumCaseName('Active'),
            backingValue: null,
            docblock: $docblock,
            file: '/path/to/file.php',
            line: 10,
            declaringClass: $declaringClass ?? new ClassName(ClassKind::class),
        );

        return new ResolvedEnumCase($caseInfo);
    }
}
