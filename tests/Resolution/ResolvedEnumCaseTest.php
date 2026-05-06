<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\EnumCaseInfo;
use Firehed\PhpLsp\Domain\EnumCaseName;
use Firehed\PhpLsp\Domain\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolvedEnumCase::class)]
class ResolvedEnumCaseTest extends TestCase
{
    use ResolvesFromInfoTestTrait;

    protected function createSubjectWithLocation(?string $file, ?int $line): ResolvedSymbol
    {
        return $this->createResolvedEnumCase(file: $file, line: $line);
    }

    protected function createSubjectWithDocblock(?string $docblock): ResolvedSymbol
    {
        return $this->createResolvedEnumCase(docblock: $docblock);
    }

    public function testImplementsInterfaces(): void
    {
        $resolved = $this->createResolvedEnumCase();

        self::assertInstanceOf(ResolvedSymbol::class, $resolved);
        self::assertInstanceOf(ResolvedMember::class, $resolved);
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

    public function testGetName(): void
    {
        $resolved = $this->createResolvedEnumCase();

        $name = $resolved->getName();

        self::assertInstanceOf(EnumCaseName::class, $name);
        self::assertSame('Active', $name->name);
    }

    public function testGetVisibilityAlwaysPublic(): void
    {
        $resolved = $this->createResolvedEnumCase();

        self::assertSame(Visibility::Public, $resolved->getVisibility());
    }

    public function testIsStaticAlwaysTrue(): void
    {
        $resolved = $this->createResolvedEnumCase();

        self::assertTrue($resolved->isStatic());
    }

    private function createResolvedEnumCase(
        ?string $file = '/path/to/file.php',
        ?int $line = 10,
        ?string $docblock = null,
        ?ClassName $declaringClass = null,
    ): ResolvedEnumCase {
        $caseInfo = new EnumCaseInfo(
            name: new EnumCaseName('Active'),
            backingValue: null,
            docblock: $docblock,
            file: $file,
            line: $line,
            declaringClass: $declaringClass ?? new ClassName(ClassKind::class),
        );

        return new ResolvedEnumCase($caseInfo);
    }
}
